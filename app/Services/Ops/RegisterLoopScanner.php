<?php

namespace App\Services\Ops;

use App\Models\Sysglobal;
use Illuminate\Support\Facades\Log;

/**
 * Scan Asterisk security log for whitelist-gated REGISTER auth loops; emit to Gatekeeper.
 */
final class RegisterLoopScanner
{
    public function __construct(
        private readonly GatekeeperOpsClient $client,
    ) {
    }

    /**
     * @return array{scanned:int, matched:int, emitted:int, skipped_not_whitelisted:int, errors:list<string>}
     */
    public function run(): array
    {
        $out = [
            'scanned' => 0,
            'matched' => 0,
            'emitted' => 0,
            'skipped_not_whitelisted' => 0,
            'errors' => [],
        ];

        if (! filter_var(config('pbx3_ops.register_loop_enabled', false), FILTER_VALIDATE_BOOL)) {
            return $out;
        }
        if (! $this->client->isConfigured()) {
            $out['errors'][] = 'gatekeeper not configured';

            return $out;
        }

        $logPath = (string) config('pbx3_ops.asterisk_messages_path', '/var/log/asterisk/security');
        // Rolling tip: until ensure-asterisk-logger-channels.sh has run, events may still be in messages.
        if ($logPath !== '' && ! is_readable($logPath)) {
            $fallback = '/var/log/asterisk/messages';
            if (is_readable($fallback)) {
                $logPath = $fallback;
            }
        }
        $jailPath = (string) config(
            'pbx3_ops.fail2ban_jail_path',
            '/etc/fail2ban/jail.d/pbx3-jails.conf'
        );
        $allowlist = IpAllowlist::fromJailFile($jailPath);
        if ($allowlist === []) {
            $out['errors'][] = 'empty ignoreip allowlist ('.$jailPath.')';

            return $out;
        }

        $statePath = (string) config('pbx3_ops.state_path', storage_path('app/ops-register-loop.json'));
        $state = $this->loadState($statePath);
        $offset = (int) ($state['offset'] ?? 0);
        $windowSec = (int) config('pbx3_ops.window_seconds', 600);
        $threshold = (int) config('pbx3_ops.threshold', 5);
        $now = time();

        /** @var array<string, array{extension:string,source_ip:string,count:int,first:int,last:int,sample:string}> $buckets */
        $buckets = is_array($state['buckets'] ?? null) ? $state['buckets'] : [];
        /** @var array<string, int> $emittedAt */
        $emittedAt = is_array($state['emitted_at'] ?? null) ? $state['emitted_at'] : [];

        if (! is_readable($logPath)) {
            $out['errors'][] = 'cannot read '.$logPath;

            return $out;
        }

        $size = filesize($logPath);
        if ($size === false) {
            $out['errors'][] = 'filesize failed';

            return $out;
        }
        if ($offset > $size) {
            $offset = 0; // rotated
        }

        $fh = fopen($logPath, 'rb');
        if ($fh === false) {
            $out['errors'][] = 'fopen failed';

            return $out;
        }
        fseek($fh, $offset);
        while (($line = fgets($fh)) !== false) {
            $out['scanned']++;
            $parsed = AsteriskAuthFailureParser::parseLine($line);
            if ($parsed === null) {
                continue;
            }
            $out['matched']++;
            $ip = $parsed['source_ip'];
            if (! IpAllowlist::contains($allowlist, $ip)) {
                $out['skipped_not_whitelisted']++;
                continue;
            }
            $ext = $parsed['extension'];
            $key = $ext.'|'.$ip;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'extension' => $ext,
                    'source_ip' => $ip,
                    'count' => 0,
                    'first' => $now,
                    'last' => $now,
                    'sample' => trim($line),
                ];
            }
            $buckets[$key]['count']++;
            $buckets[$key]['last'] = $now;
            if ($buckets[$key]['sample'] === '') {
                $buckets[$key]['sample'] = trim($line);
            }
        }
        $newOffset = ftell($fh);
        fclose($fh);
        if ($newOffset !== false) {
            $offset = $newOffset;
        }

        // Drop stale buckets
        foreach ($buckets as $key => $b) {
            if (($now - (int) $b['last']) > $windowSec) {
                unset($buckets[$key]);
            }
        }

        $globals = Sysglobal::query()->first();
        $instanceId = trim((string) ($globals?->id ?? ''));
        $fqdn = trim((string) ($globals?->fqdn ?? ''));
        $label = $fqdn !== '' ? explode('.', $fqdn, 2)[0] : $instanceId;
        if ($instanceId === '') {
            $out['errors'][] = 'globals.id empty';
            $this->saveState($statePath, $offset, $buckets, $emittedAt);

            return $out;
        }

        $cooldown = (int) config('pbx3_ops.emit_cooldown_seconds', 1800);

        foreach ($buckets as $key => $b) {
            if ((int) $b['count'] < $threshold) {
                continue;
            }
            if (($now - (int) $b['first']) > $windowSec && (int) $b['count'] < $threshold) {
                continue;
            }
            // Require threshold within window from first hit in bucket
            if (($now - (int) $b['first']) > $windowSec) {
                // reset aging bucket that grew slowly
                unset($buckets[$key]);
                continue;
            }
            $lastEmit = (int) ($emittedAt[$key] ?? 0);
            if ($lastEmit > 0 && ($now - $lastEmit) < $cooldown) {
                continue;
            }

            try {
                $authId = (string) $b['extension'];
                $resolved = EndpointUidResolver::resolve($authId);
                $this->client->postEvent([
                    'type' => 'misconfig_register',
                    'instance_id' => $instanceId,
                    'instance_label' => $label,
                    'fqdn' => $fqdn,
                    // Dialable ext when known; else Asterisk auth id (shortuid / legacy pkey).
                    'extension' => $resolved['extension'],
                    'endpoint_uid' => $resolved['endpoint_uid'],
                    'endpoint_name' => $resolved['endpoint_name'],
                    'source_ip' => $b['source_ip'],
                    'count' => (int) $b['count'],
                    'window_seconds' => $windowSec,
                    'sample' => mb_substr((string) $b['sample'], 0, 240),
                ]);
                $emittedAt[$key] = $now;
                $out['emitted']++;
                // reset count after emit so we need another burst
                unset($buckets[$key]);
            } catch (\Throwable $e) {
                $out['errors'][] = $e->getMessage();
                Log::warning('register-loop emit failed', ['error' => $e->getMessage()]);
            }
        }

        $this->saveState($statePath, $offset, $buckets, $emittedAt);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $buckets
     * @param  array<string, int>  $emittedAt
     */
    private function saveState(string $path, int $offset, array $buckets, array $emittedAt): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        file_put_contents($path, json_encode([
            'offset' => $offset,
            'buckets' => $buckets,
            'emitted_at' => $emittedAt,
            'updated_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
