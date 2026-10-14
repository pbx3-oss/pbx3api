<?php

namespace App\Services;

use App\Services\Cdr\CdrIndexService;
use App\Support\SiteTimezone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cached aggregates for Instance SPA Home (ops pulse).
 *
 * Blocks are separable so clients can request include=system,live,cdr
 * (tenant Home later can omit host/system).
 */
class HomePulseService
{
    public const TTL_LIVE = 45;

    public const TTL_SYSTEM = 45;

    public const TTL_CDR = 90;

    public function __construct(
        private CdrIndexService $cdr
    ) {}

    /**
     * @param  list<string>  $include  subset of system|live|cdr
     * @param  array{
     *   accountcode?: string|null,
     *   accountcodes?: list<string>|null
     * }  $cdrFilters
     * @param  list<string>|null  $clusterScope  null = admin (all); empty = none; else shortuids
     * @return array<string, mixed>
     */
    public function pulse(array $include, array $cdrFilters = [], ?array $clusterScope = null): array
    {
        $out = [];

        if (in_array('system', $include, true)) {
            try {
                $out['system'] = $this->systemPosture();
            } catch (\Throwable $e) {
                Log::warning('home pulse system failed', ['error' => $e->getMessage()]);
                $out['system'] = $this->emptySystemPosture();
            }
        }
        if (in_array('live', $include, true)) {
            try {
                $out['live'] = $this->livePosture($clusterScope);
            } catch (\Throwable $e) {
                Log::warning('home pulse live failed', ['error' => $e->getMessage()]);
                $out['live'] = [
                    'current_calls' => null,
                    'current_calls_scope' => 'instance',
                    'endpoints_defined' => null,
                    'ami_ok' => false,
                ];
            }
        }
        if (in_array('cdr', $include, true)) {
            try {
                $out['cdr'] = $this->cdrPulse($cdrFilters);
            } catch (\Throwable $e) {
                Log::warning('home pulse cdr failed', ['error' => $e->getMessage()]);
                $out['cdr'] = [
                    'available' => false,
                    'timezone' => SiteTimezone::id(),
                    'volume_24h' => ['labels' => [], 'answered' => [], 'other' => []],
                    'outcome_today' => [
                        'answered' => 0,
                        'no_answer' => 0,
                        'busy' => 0,
                        'failed' => 0,
                        'other' => 0,
                    ],
                    'dest_where_today' => [
                        'internal' => 0,
                        'high_cost' => 0,
                        'international' => 0,
                        'domestic' => 0,
                        'empty' => 0,
                        'total' => 0,
                        'home_country_code' => (string) config('pbx3_cdr.home_country_code', '44'),
                    ],
                ];
            }
        }

        return $out;
    }

    /**
     * Drop cached system posture (e.g. after start/stop Asterisk) so the next
     * Home pulse returns a fresh pbx_runstate instead of a stale RUNNING/STOPPED.
     */
    public function invalidateSystemPosture(): void
    {
        try {
            Cache::forget('home_pulse:system');
        } catch (\Throwable $e) {
            Log::warning('home pulse system cache forget failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Thin host pulse. Prefer /proc when open_basedir allows it; otherwise shell
     * fallbacks (golden nginx open_basedir excludes /proc).
     *
     * @return array{
     *     load1: float,
     *     load5: float,
     *     load15: float,
     *     cpus: int,
     *     mem_used_pct: ?float,
     *     mem_total_mb: ?int,
     *     disk_used_pct: ?float,
     *     disk_total_gb: ?float,
     *     pbx_runstate: string
     * }
     */
    public function systemPosture(): array
    {
        return $this->rememberOrCompute('home_pulse:system', self::TTL_SYSTEM, function () {
            [$load1, $load5, $load15] = $this->readLoadAvg();
            $cpus = $this->readCpuCount();
            [$memUsedPct, $memTotalMb] = $this->readMemory();
            [$diskUsedPct, $diskTotalGb] = $this->readDisk();

            $runstate = 'STOPPED';
            try {
                if (function_exists('pbx_is_running') && pbx_is_running()) {
                    $runstate = 'RUNNING';
                }
            } catch (\Throwable) {
                // ignore
            }

            return [
                'load1' => round($load1, 2),
                'load5' => round($load5, 2),
                'load15' => round($load15, 2),
                'cpus' => $cpus,
                'mem_used_pct' => $memUsedPct,
                'mem_total_mb' => $memTotalMb,
                'disk_used_pct' => $diskUsedPct,
                'disk_total_gb' => $diskTotalGb,
                'pbx_runstate' => $runstate,
            ];
        });
    }

    /**
     * @return array{
     *     load1: float,
     *     load5: float,
     *     load15: float,
     *     cpus: int,
     *     mem_used_pct: null,
     *     mem_total_mb: null,
     *     disk_used_pct: null,
     *     disk_total_gb: null,
     *     pbx_runstate: string
     * }
     */
    private function emptySystemPosture(): array
    {
        return [
            'load1' => 0.0,
            'load5' => 0.0,
            'load15' => 0.0,
            'cpus' => 1,
            'mem_used_pct' => null,
            'mem_total_mb' => null,
            'disk_used_pct' => null,
            'disk_total_gb' => null,
            'pbx_runstate' => 'STOPPED',
        ];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function readLoadAvg(): array
    {
        $raw = $this->readProcFile('/proc/loadavg');
        if ($raw === null) {
            $raw = trim((string) @shell_exec('cat /proc/loadavg 2>/dev/null'));
        }
        if ($raw !== '') {
            $parts = preg_split('/\s+/', $raw) ?: [];

            return [
                (float) ($parts[0] ?? 0),
                (float) ($parts[1] ?? 0),
                (float) ($parts[2] ?? 0),
            ];
        }

        $avg = @sys_getloadavg();
        if (is_array($avg)) {
            return [
                (float) ($avg[0] ?? 0),
                (float) ($avg[1] ?? 0),
                (float) ($avg[2] ?? 0),
            ];
        }

        return [0.0, 0.0, 0.0];
    }

    private function readCpuCount(): int
    {
        $raw = $this->readProcFile('/proc/cpuinfo');
        if ($raw === null) {
            $raw = (string) @shell_exec('nproc 2>/dev/null');
            $n = (int) trim($raw);
            if ($n > 0) {
                return $n;
            }

            return 1;
        }

        return max(1, substr_count($raw, 'processor'));
    }

    /** @return array{0: ?float, 1: ?int} */
    private function readMemory(): array
    {
        $memTotalKb = 0;
        $memAvailKb = 0;
        $raw = $this->readProcFile('/proc/meminfo');
        if ($raw !== null) {
            foreach (explode("\n", $raw) as $line) {
                if (str_starts_with($line, 'MemTotal:')) {
                    $memTotalKb = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                } elseif (str_starts_with($line, 'MemAvailable:')) {
                    $memAvailKb = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                }
            }
        } else {
            // Same approach as sysnotes (open_basedir-safe via free binary).
            $free = (string) @shell_exec('/usr/bin/free -b 2>/dev/null');
            if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/', $free, $m)) {
                $tot = (int) $m[1];
                $used = (int) $m[2];
                if ($tot > 0) {
                    return [
                        round(($used / $tot) * 100, 1),
                        (int) round($tot / 1048576),
                    ];
                }
            }
        }

        if ($memTotalKb > 0) {
            $usedKb = max(0, $memTotalKb - $memAvailKb);

            return [
                round(($usedKb / $memTotalKb) * 100, 1),
                (int) round($memTotalKb / 1024),
            ];
        }

        return [null, null];
    }

    /** @return array{0: ?float, 1: ?float} */
    private function readDisk(): array
    {
        // Prefer paths inside typical open_basedir; fall back to df.
        foreach (['/opt/pbx3', '/opt/pbx3api', '/tmp', '/'] as $diskPath) {
            $diskTotal = @disk_total_space($diskPath);
            $diskFree = @disk_free_space($diskPath);
            if (is_float($diskTotal) && $diskTotal > 0 && is_float($diskFree)) {
                return [
                    round((($diskTotal - $diskFree) / $diskTotal) * 100, 1),
                    round($diskTotal / (1024 ** 3), 1),
                ];
            }
        }

        $df = (string) @shell_exec('/bin/df -k /opt/pbx3 2>/dev/null');
        if (preg_match('/\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d{1,3})%/', $df, $m)) {
            $totalKb = (int) $m[1];

            return [
                (float) $m[4],
                round($totalKb / (1024 * 1024), 1),
            ];
        }

        return [null, null];
    }

    /**
     * Read a /proc file when open_basedir allows; otherwise null (no throw).
     */
    private function readProcFile(string $path): ?string
    {
        try {
            if (! @is_readable($path)) {
                return null;
            }
            $raw = @file_get_contents($path);

            return $raw === false ? null : (string) $raw;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>|null  $clusterScope
     * @return array{
     *   current_calls: ?int,
     *   current_calls_scope: string,
     *   endpoints_defined: ?int,
     *   ami_ok: bool
     * }
     */
    public function livePosture(?array $clusterScope = null): array
    {
        $scopeKey = $clusterScope === null ? 'admin' : ('c:'.implode(',', $clusterScope));

        return $this->rememberOrCompute('home_pulse:live:'.$scopeKey, self::TTL_LIVE, function () use ($clusterScope) {
            $endpoints = null;
            try {
                $q = DB::table('ipphone');
                if ($clusterScope !== null) {
                    if ($clusterScope === []) {
                        $endpoints = 0;
                    } else {
                        $endpoints = (int) $q->whereIn('cluster', $clusterScope)->count();
                    }
                } else {
                    $endpoints = (int) $q->count();
                }
            } catch (\Throwable $e) {
                $endpoints = null;
            }

            $currentCalls = null;
            $amiOk = false;

            // Prefer AMI even if ps-based pbx_is_running fails under restricted shells.
            if (function_exists('get_ami_handle')) {
                try {
                    $amiHandle = get_ami_handle();
                    // Until blank line — amiQuery() waits the full socket timeout (~3s).
                    $amirets = $amiHandle->amiQueryUntilBlankLine("Action: CoreStatus\r\n");
                    $amiHandle->logout();

                    $amiArray = [];
                    foreach (explode("\r\n", (string) $amirets) as $line) {
                        if (! preg_match('/:/', $line)) {
                            continue;
                        }
                        $couplet = explode(': ', $line, 2);
                        if (count($couplet) === 2) {
                            $amiArray[$couplet[0]] = $couplet[1];
                        }
                    }

                    if (isset($amiArray['CoreCurrentCalls']) && is_numeric($amiArray['CoreCurrentCalls'])) {
                        $currentCalls = (int) $amiArray['CoreCurrentCalls'];
                        $amiOk = true;
                    } elseif (isset($amiArray['CurrentCalls']) && is_numeric($amiArray['CurrentCalls'])) {
                        $currentCalls = (int) $amiArray['CurrentCalls'];
                        $amiOk = true;
                    }
                } catch (\Throwable $e) {
                    Log::debug('home pulse AMI CoreStatus failed', ['error' => $e->getMessage()]);
                    $amiOk = false;
                    $currentCalls = null;
                }
            }

            return [
                'current_calls' => $currentCalls,
                'current_calls_scope' => 'instance',
                'endpoints_defined' => $endpoints,
                'ami_ok' => $amiOk,
            ];
        });
    }

    /**
     * @param  array{
     *   accountcode?: string|null,
     *   accountcodes?: list<string>|null
     * }  $filters
     * @return array{
     *   available: bool,
     *   volume_24h: array{labels: list<string>, answered: list<int>, other: list<int>},
     *   outcome_today: array{answered: int, no_answer: int, busy: int, failed: int, other: int}
     * }
     */
    public function cdrPulse(array $filters = []): array
    {
        $scopeKey = '';
        if (isset($filters['accountcode'])) {
            $scopeKey .= 'a:'.$filters['accountcode'];
        }
        if (isset($filters['accountcodes']) && is_array($filters['accountcodes'])) {
            $scopeKey .= 'as:'.implode(',', $filters['accountcodes']);
        }
        if ($scopeKey === '') {
            $scopeKey = 'all';
        }
        $scopeKey .= '|tz:'.SiteTimezone::id();

        return $this->rememberOrCompute('home_pulse:cdr:'.$scopeKey, self::TTL_CDR, function () use ($filters) {
            $volume = $this->cdr->volumeLast24h($filters);
            $outcome = $this->cdr->outcomeToday($filters);
            $dest = $this->cdr->destWhereToday($filters);
            $available = ($volume['available'] ?? false) && ($outcome['available'] ?? false);
            $timezone = (string) ($volume['timezone'] ?? $outcome['timezone'] ?? SiteTimezone::id());

            return [
                'available' => $available,
                'timezone' => $timezone,
                'volume_24h' => [
                    'labels' => $volume['labels'] ?? [],
                    'answered' => $volume['answered'] ?? [],
                    'other' => $volume['other'] ?? [],
                ],
                'outcome_today' => [
                    'answered' => (int) ($outcome['answered'] ?? 0),
                    'no_answer' => (int) ($outcome['no_answer'] ?? 0),
                    'busy' => (int) ($outcome['busy'] ?? 0),
                    'failed' => (int) ($outcome['failed'] ?? 0),
                    'other' => (int) ($outcome['other'] ?? 0),
                ],
                'dest_where_today' => [
                    'internal' => (int) ($dest['internal'] ?? 0),
                    'high_cost' => (int) ($dest['high_cost'] ?? 0),
                    'international' => (int) ($dest['international'] ?? 0),
                    'domestic' => (int) ($dest['domestic'] ?? 0),
                    'empty' => (int) ($dest['empty'] ?? 0),
                    'total' => (int) ($dest['total'] ?? 0),
                    'home_country_code' => (string) ($dest['home_country_code'] ?? config('pbx3_cdr.home_country_code', '44')),
                ],
            ];
        });
    }

    /**
     * Cache::remember, but if the cache store cannot write (e.g. root-owned
     * framework/cache after sudo artisan), still return a fresh compute.
     *
     * @template T
     * @param  callable(): T  $compute
     * @return T
     */
    private function rememberOrCompute(string $key, int $ttl, callable $compute): mixed
    {
        try {
            return Cache::remember($key, $ttl, $compute);
        } catch (\Throwable $e) {
            Log::warning('home pulse cache unavailable; computing uncached', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $compute();
        }
    }

    /**
     * @param  string|null  $raw  CSV include query
     * @return list<string>
     */
    public static function parseInclude(?string $raw): array
    {
        $allowed = ['system', 'live', 'cdr'];
        if ($raw === null || trim($raw) === '') {
            return $allowed;
        }

        $parts = array_filter(array_map('trim', explode(',', strtolower($raw))));
        $out = [];
        foreach ($parts as $p) {
            if (in_array($p, $allowed, true) && ! in_array($p, $out, true)) {
                $out[] = $p;
            }
        }

        return $out === [] ? $allowed : $out;
    }
}
