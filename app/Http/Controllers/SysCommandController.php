<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Services\HomePulseService;
use App\Services\Snapshot\SnapshotRetention;
//use Illuminate\Support\Facades\Response;
//use Illuminate\Support\Facades\Validator;

class SysCommandController extends Controller

/**
 * SysCommands
 *
 * GET commitstatus = dirty state (Save vs Commit).
 * GET commit = run generator + Asterisk reload, clear dirty.
 */
{
    private $commands = [
        'commit' => 'run generator and Asterisk reload',
        'commitstatus' => 'returns { dirty: boolean }',
        'reboot' => 'null',
        'pbxstart' => 'null',
        'pbxstop' => 'null',
        'pbxrunstate' => 'returns PBX state (boolean)',
        'sipdebug/status' => 'home SIP debug session status',
        'sipdebug/arm' => 'arm home SIP debug (text ± pcap, TTL)',
        'sipdebug/disarm' => 'disarm home SIP debug',
    ];

    /**
     * Return SysCommand index.
     */
    public function index () {
        return response()->json($this->commands, 200);
    }

    /**
     * GET commit status: true if there are uncommitted DB changes (Commit button should be red).
     */
    public function commitstatus () {
        try {
            $row = DB::table('globals')->first(['mycommit']);
            $dirty = isset($row->mycommit) && strtoupper((string) $row->mycommit) === 'YES';
            return response()->json(['dirty' => $dirty], 200);
        } catch (\Throwable $e) {
            return response()->json(['dirty' => false], 200);
        }
    }

    /**
     * Run Asterisk config generator then reload. Clears dirty state (mycommit = NO).
     * After a successful commit, takes a DB snapshot (S9.6) and FIFO-prunes /opt/pbx3/snap/ (S9.7).
     * Snapshot failure is logged but does not fail the commit (config is already applied).
     */
    public function commit () {
        $genAst = env('PBX3_GENAST_SCRIPT', '/opt/pbx3/scripts/genAst.sh');
        $asterisk = env('PBX3_ASTERISK_EXEC', '/usr/sbin/asterisk');
        if (is_readable($genAst)) {
            exec('/bin/sh ' . escapeshellarg($genAst) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                return response()->json(['message' => 'Generator failed', 'detail' => implode("\n", $out)], 502);
            }
        }
        exec(escapeshellarg($asterisk) . ' -rx ' . escapeshellarg('core reload') . ' 2>&1', $relout, $relcode);
        try {
            DB::table('globals')->update(['mycommit' => 'NO']);
        } catch (\Throwable $e) {
            // ignore
        }

        $payload = ['message' => 'Commit completed'];
        try {
            $snapshotName = create_new_snapshot();
            $pruned = app(SnapshotRetention::class)->pruneExcess();
            $payload['snapshot'] = $snapshotName;
            if ($pruned !== []) {
                $payload['snapshots_pruned'] = $pruned;
            }
        } catch (\Throwable $e) {
            Log::warning('commit succeeded but snapshot failed', [
                'error' => $e->getMessage(),
            ]);
            $payload['snapshot_error'] = $e->getMessage();
        }

        return response()->json($payload, 200);
    } 

    public function reboot ()
    {
        Log::info('SysCommandController::reboot called');
        [$response, $err] = pbx3_request_syscmd('/sbin/reboot');
        if ($err !== null) {
            Log::warning('syscommands/reboot failed', ['error' => $err]);
            return response()->json([
                'message' => 'Reboot command failed',
                'detail' => $err,
            ], 502);
        }
        return response()->json(['message' => 'Reboot issued'], 200);
    }

    public function start ()
    {
        Log::info('SysCommandController::start called');
        if (`/bin/ps -e 2>/dev/null | /bin/grep asterisk | /bin/grep -v grep`) {
            return response()->json(['message' => 'PBX already running'], 503);
        }
        [$response, $err] = pbx3_request_syscmd('/bin/systemctl start asterisk');
        if ($err !== null) {
            Log::warning('syscommands/start failed', ['error' => $err]);
            return response()->json([
                'message' => 'Start PBX command failed',
                'detail' => $err,
            ], 502);
        }
        app(HomePulseService::class)->invalidateSystemPosture();
        return response()->json(['message' => 'PBX started'], 200);
    }

    public function stop ()
    {
        Log::info('SysCommandController::stop called');
        if (!`/bin/ps -e 2>/dev/null | /bin/grep asterisk | /bin/grep -v grep`) {
            return response()->json(['message' => 'PBX not running'], 503);
        }
        [$response, $err] = pbx3_request_syscmd('/bin/systemctl stop asterisk');
        if ($err !== null) {
            Log::warning('syscommands/stop failed', ['error' => $err]);
            return response()->json([
                'message' => 'Stop PBX command failed',
                'detail' => $err,
            ], 502);
        }
        app(HomePulseService::class)->invalidateSystemPosture();
        return response()->json(['message' => 'PBX stopped'], 200);
    }

    /**
     * PUT syscommands/hostname — set system hostname (via syshelper).
     * Body: { "hostname": "newname" }. Hostname: alphanumeric and hyphens only, 1–253 chars.
     */
    public function sethostname(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'hostname' => 'required|string|max:253|regex:/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$|^[a-zA-Z0-9]$/',
        ], [
            'hostname.regex' => 'Hostname must be alphanumeric with optional hyphens or dots (e.g. pbx1 or node1.example).',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $hostname = trim($request->input('hostname'));
        if ($hostname === '') {
            return response()->json(['hostname' => ['Hostname is required.']], 422);
        }
        [$_, $err] = pbx3_request_syscmd('/usr/bin/hostnamectl set-hostname ' . escapeshellarg($hostname) . ' 2>&1');
        if ($err !== null) {
            Log::warning('syscommands/sethostname hostnamectl failed', ['error' => $err]);
            return response()->json(['message' => 'Failed to set hostname', 'detail' => $err], 502);
        }
        $sedSafe = preg_replace('/[^a-zA-Z0-9.-]/', '', $hostname);
        $hostsCmd = 'grep -q "^127\\.0\\.1\\.1" /etc/hosts && sed -i "s/^127\\.0\\.1\\.1.*/127.0.1.1 ' . addslashes($sedSafe) . '/" /etc/hosts || echo "127.0.1.1 ' . addslashes($sedSafe) . '" >> /etc/hosts';
        [$_, $errHosts] = pbx3_request_syscmd($hostsCmd . ' 2>&1');
        if ($errHosts !== null) {
            Log::warning('syscommands/sethostname /etc/hosts update failed', ['error' => $errHosts]);
        }
        return response()->json(['hostname' => $hostname], 200);
    }

    public function pbxrunstate () {

        if  (`/bin/ps -e | /bin/grep asterisk | /bin/grep -v grep`) {
            return response()->json(['pbxrunstate' => True],200);
        }
        return response()->json(['pbxrunstate' => False],200);
    }

    /**
     * GET sysnotes — read-only system info for Home panel (equivalent to legacy printSysNotes).
     * Returns system, network, and resource data for display; no sensitive or editable fields.
     */
    public function sysnotes()
    {
        $asterisk = env('PBX3_ASTERISK_EXEC', '/usr/sbin/asterisk');

        // Instance line on Home: globals.fqdn when set; else globals.id (KSUID). (pkey is fixed "global".)
        $instance = null;
        $sitename = null;
        try {
            $g = DB::table('globals')->first();
            if ($g) {
                $fqdn = isset($g->fqdn) ? trim((string) $g->fqdn) : '';
                $instance = $fqdn !== '' ? $fqdn : ($g->id ?? null);
                $sn = isset($g->sitename) ? trim((string) $g->sitename) : '';
                if ($sn !== '') {
                    $sitename = $sn;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $system = [
            'instance' => $instance,
            'sitename' => $sitename,
            'distro' => $this->safeShell("lsb_release -ds 2>/dev/null"),
            'asterisk_release' => $this->getAsteriskRelease($asterisk),
            'app_release' => trim(shell_exec("dpkg-query -W -f '\${Version}' pbx3 2>/dev/null") ?: '') ?: null,
            /** Laravel framework version (API / pbx3api). */
            'laravel_version' => Application::VERSION,
            /** PHP runtime for the API. */
            'php_version' => PHP_VERSION,
            'endpoints_defined' => null,
        ];

        try {
            $extcount = DB::table('ipphone')->count();
            $system['endpoints_defined'] = $extcount;
        } catch (\Throwable $e) {
            $system['endpoints_defined'] = null;
        }

        $network = [
            'hostname' => gethostname() ?: null,
            'local_ip' => $this->getLocalIpV4(),
            'mac' => $this->safeShell("ip route show default 2>/dev/null | head -1 | awk '{for(i=1;i<=NF;i++)if(\$i==\"dev\"){print \$(i+1);exit}}' | xargs -I{} ip link show {} 2>/dev/null | awk '/ether/{print \$2}'"),
            'public_ip' => $this->safeShell("curl -s -m 2 ifconfig.me 2>/dev/null") ?: null,
        ];

        $free = `/usr/bin/free -b 2>/dev/null`;
        $totmem = $freemem = null;
        if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/', $free, $m)) {
            $totmem = (int) $m[1];
            $freemem = (int) $m[3];
        }
        $df = `/bin/df -k . 2>/dev/null`;
        $diskusage = null;
        if (preg_match('/(\d{1,2})%/', $df, $m)) {
            $diskusage = $m[1] . '%';
        }

        $masteroclo = trim(`$asterisk -rx 'database get STAT OCSTAT' 2>/dev/null`);
        $masteroclo = preg_replace('/^.*:\s*/', '', $masteroclo);
        if (preg_match('/not\s+found/i', $masteroclo) || $masteroclo === '') {
            $masteroclo = 'AUTO';
        }

        $clusteroclo = null;
        try {
            $c = DB::table('cluster')->where('pkey', 'default')->first();
            if ($c && isset($c->oclo)) {
                $clusteroclo = $c->oclo;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $runstate = `/bin/ps -e 2>/dev/null | /bin/grep asterisk | /bin/grep -v grep` ? 'RUNNING' : 'STOPPED';

        $resource = [
            'disk_usage' => $diskusage,
            'ram_total' => $totmem,
            'ram_free' => $freemem,
            'pbx_runstate' => $runstate,
            'masteroclo' => $masteroclo,
            'timer_state' => $clusteroclo,
        ];

        $dns = [];
        [$resolvOut] = pbx3_request_syscmd('cat /etc/resolv.dnsmasq 2>/dev/null');
        if ($resolvOut !== null && $resolvOut !== '') {
            foreach (explode("\n", $resolvOut) as $line) {
                $line = trim($line);
                if (preg_match('/^nameserver\s+(\S+)/', $line, $m)) {
                    $dns[] = trim($m[1]);
                }
            }
        }

        $smtp = null;
        [$smtpOut] = pbx3_request_syscmd('test -f /etc/ssmtp/ssmtp.conf && cat /etc/ssmtp/ssmtp.conf 2>/dev/null');
        if ($smtpOut !== null && $smtpOut !== '') {
            $smtp = ['mailhub' => '', 'auth_user' => '', 'auth_pass' => '', 'use_tls' => 'NO', 'use_starttls' => 'NO'];
            foreach (explode("\n", $smtpOut) as $line) {
                if (preg_match('/^mailhub=\s*(.*)$/', trim($line), $m)) {
                    $smtp['mailhub'] = trim($m[1]);
                } elseif (preg_match('/^AuthUser=\s*(.*)$/', trim($line), $m)) {
                    $smtp['auth_user'] = trim($m[1]);
                } elseif (preg_match('/^AuthPass=\s*(.*)$/', trim($line), $m)) {
                    $smtp['auth_pass'] = trim($m[1]);
                } elseif (preg_match('/^UseTLS=\s*(.*)$/', trim($line), $m)) {
                    $smtp['use_tls'] = strtoupper(trim($m[1]));
                } elseif (preg_match('/^UseSTARTTLS=\s*(.*)$/', trim($line), $m)) {
                    $smtp['use_starttls'] = strtoupper(trim($m[1]));
                }
            }
        }

        $timezone = null;
        [$tzOut] = pbx3_request_syscmd('cat /etc/timezone 2>/dev/null');
        if ($tzOut !== null && trim($tzOut) !== '') {
            $timezone = trim($tzOut);
        }

        $icmp = true; // UFW default allows echo-request
        [$ufwSt] = pbx3_request_syscmd("ufw status 2>/dev/null | head -1");
        if (!is_string($ufwSt) || stripos($ufwSt, 'Status: active') === false) {
            $icmp = false;
        }

        return response()->json([
            'system' => $system,
            'network' => $network,
            'resource' => $resource,
            'dns' => $dns,
            'smtp' => $smtp,
            'timezone' => $timezone,
            'icmp' => $icmp,
        ], 200);
    }

    /**
     * PUT syscommands/dns — set DNS nameservers in /etc/resolv.dnsmasq (via syshelper).
     * Body: { "nameservers": ["8.8.8.8", "8.8.4.4", ...] }. One per line in file; dnsmasq restarted.
     */
    public function setdns(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nameservers' => 'required|array',
            'nameservers.*' => 'nullable|string|max:253',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $nameservers = array_values(array_filter(array_map('trim', $request->input('nameservers', []))));
        foreach ($nameservers as $ns) {
            if ($ns === '') {
                continue;
            }
            $isIp = filter_var($ns, FILTER_VALIDATE_IP) !== false;
            $isHostname = preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $ns);
            if (!$isIp && !$isHostname) {
                return response()->json(['nameservers' => ['Invalid nameserver: use IP address or hostname (e.g. 8.8.8.8 or ns.example.com).']], 422);
            }
        }
        if (count($nameservers) === 0) {
            return response()->json(['nameservers' => ['At least one nameserver is required.']], 422);
        }
        $parts = [];
        foreach ($nameservers as $i => $ns) {
            $esc = escapeshellarg($ns);
            $parts[] = ($i === 0 ? "echo nameserver $esc > /etc/resolv.dnsmasq" : "echo nameserver $esc >> /etc/resolv.dnsmasq");
        }
        [$_, $err] = pbx3_request_syscmd(implode(' && ', $parts) . ' 2>&1');
        if ($err !== null) {
            Log::warning('syscommands/setdns write failed', ['error' => $err]);
            return response()->json(['message' => 'Failed to write DNS config', 'detail' => $err], 502);
        }
        pbx3_request_syscmd('/bin/systemctl restart dnsmasq 2>&1');
        return response()->json(['nameservers' => $nameservers], 200);
    }

    /**
     * PUT syscommands/smtp — write /etc/ssmtp/ssmtp.conf (via syshelper).
     * Body: { mailhub, auth_user?, auth_pass?, use_tls ("YES"/"NO"), use_starttls ("YES"/"NO") }.
     * Only applied if /etc/ssmtp/ssmtp.conf exists on the system.
     */
    public function setsmtp(Request $request)
    {
        [$exists] = pbx3_request_syscmd('test -f /etc/ssmtp/ssmtp.conf && echo yes 2>/dev/null');
        if ($exists === null || trim($exists) !== 'yes') {
            return response()->json(['message' => 'SMTP config not present (no /etc/ssmtp/ssmtp.conf)'], 404);
        }
        $validator = Validator::make($request->all(), [
            'mailhub' => 'required|string|max:253',
            'auth_user' => 'nullable|string|max:253',
            'auth_pass' => 'nullable|string|max:255',
            'use_tls' => 'nullable|string|in:YES,NO',
            'use_starttls' => 'nullable|string|in:YES,NO',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $mailhub = trim($request->input('mailhub'));
        $authUser = trim($request->input('auth_user', ''));
        $authPass = trim($request->input('auth_pass', ''));
        $useTls = strtoupper($request->input('use_tls', 'NO')) === 'YES' ? 'YES' : 'NO';
        $useStarttls = strtoupper($request->input('use_starttls', 'NO')) === 'YES' ? 'YES' : 'NO';
        [$hostnameOut] = pbx3_request_syscmd('hostname 2>/dev/null');
        $hostname = ($hostnameOut !== null && trim($hostnameOut) !== '') ? trim($hostnameOut) : 'localhost';
        $lines = ["hostname={$hostname}", 'FromLineOverride=YES', "mailhub={$mailhub}"];
        if ($authUser !== '') {
            $lines[] = "AuthUser={$authUser}";
            if ($authPass !== '') {
                $lines[] = "AuthPass={$authPass}";
            }
        }
        $lines[] = "UseTLS={$useTls}";
        $lines[] = "UseSTARTTLS={$useStarttls}";
        $content = implode("\n", $lines);
        $tmp = tempnam('/tmp', 'ssmtp.');
        file_put_contents($tmp, $content);
        [$_, $err] = pbx3_request_syscmd('/bin/mv ' . escapeshellarg($tmp) . ' /etc/ssmtp/ssmtp.conf && chmod 664 /etc/ssmtp/ssmtp.conf 2>&1');
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
        if ($err !== null) {
            Log::warning('syscommands/setsmtp failed', ['error' => $err]);
            return response()->json(['message' => 'Failed to write SMTP config', 'detail' => $err], 502);
        }
        return response()->json(['mailhub' => $mailhub], 200);
    }

    /**
     * GET syscommands/timezones — list valid timezone identifiers for dropdown (DateTimeZone::listIdentifiers).
     */
    public function timezones()
    {
        return response()->json(\DateTimeZone::listIdentifiers(), 200);
    }

    /**
     * PUT syscommands/timezone — set system timezone (via syshelper).
     * Body: { "timezone": "Europe/London" }. Must be a valid PHP timezone identifier.
     */
    public function settimezone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'timezone' => 'required|string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $tz = trim($request->input('timezone'));
        $valid = \DateTimeZone::listIdentifiers();
        if (!in_array($tz, $valid, true)) {
            return response()->json(['timezone' => ['Invalid timezone identifier.']], 422);
        }
        $esc = escapeshellarg($tz);
        [$_, $err] = pbx3_request_syscmd('rm -f /etc/localtime && ln -sf /usr/share/zoneinfo/' . $esc . ' /etc/localtime && echo ' . $esc . ' > /etc/timezone && dpkg-reconfigure -f noninteractive tzdata 2>&1');
        if ($err !== null) {
            Log::warning('syscommands/settimezone failed', ['error' => $err]);
            return response()->json(['message' => 'Failed to set timezone', 'detail' => $err], 502);
        }
        return response()->json(['timezone' => $tz], 200);
    }

    /**
     * PUT syscommands/icmp — allow or reject ping (ICMP).
     * Under UFW: not exposed — Ubuntu UFW keeps echo allowed by default.
     * Body: { "allow": true|false }.
     */
    public function seticmp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'allow' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        return response()->json([
            'allow' => true,
            'message' => 'ICMP follows UFW defaults (echo allowed). Toggle not implemented under UFW.',
        ], 200);
    }

    /**
     * GET syscommands/sipdebug/status — session-armed home SIP debug state.
     * Spec: HOME_SIP_LOGGING_REQUIREMENTS.md
     */
    public function sipdebugStatus()
    {
        $script = '/opt/pbx3/scripts/sip-debug-status.sh';
        [$out, $err] = pbx3_request_syscmd('test -x '.escapeshellarg($script).' && '.escapeshellarg($script).' 2>&1');
        if ($err !== null || $out === null || trim($out) === '') {
            return response()->json([
                'armed' => false,
                'text' => false,
                'pcap' => false,
                'pcap_running' => false,
                'expires_at' => '',
                'ttl_remaining_sec' => 0,
                'available' => false,
                'detail' => $err ?? 'sip-debug-status.sh missing — tip-deploy pbx3 package/scripts',
            ], 200);
        }
        $decoded = json_decode(trim($out), true);
        if (! is_array($decoded)) {
            return response()->json(['message' => 'Invalid status JSON', 'detail' => $out], 502);
        }
        $decoded['available'] = true;

        return response()->json($decoded, 200);
    }

    /**
     * POST syscommands/sipdebug/arm — { ttl_minutes?: 15-60, pcap?: bool }
     */
    public function sipdebugArm(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ttl_minutes' => 'sometimes|integer|min:15|max:60',
            'pcap' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $ttl = (int) $request->input('ttl_minutes', 30);
        $pcap = $request->boolean('pcap');
        $script = '/opt/pbx3/scripts/sip-debug-arm.sh';
        $cmd = escapeshellarg($script).' --ttl '.escapeshellarg((string) $ttl);
        if ($pcap) {
            $cmd .= ' --pcap';
        }
        [$out, $err] = pbx3_request_syscmd('test -x '.escapeshellarg($script).' && '.$cmd.' 2>&1');
        if ($err !== null) {
            Log::warning('sipdebug/arm failed', ['error' => $err, 'out' => $out]);

            return response()->json(['message' => 'Failed to arm SIP debug', 'detail' => $err ?: $out], 502);
        }

        return $this->sipdebugStatus();
    }

    /**
     * POST syscommands/sipdebug/disarm
     */
    public function sipdebugDisarm()
    {
        $script = '/opt/pbx3/scripts/sip-debug-disarm.sh';
        [$out, $err] = pbx3_request_syscmd('test -x '.escapeshellarg($script).' && '.escapeshellarg($script).' 2>&1');
        if ($err !== null) {
            Log::warning('sipdebug/disarm failed', ['error' => $err, 'out' => $out]);

            return response()->json(['message' => 'Failed to disarm SIP debug', 'detail' => $err ?: $out], 502);
        }

        return $this->sipdebugStatus();
    }

    private function safeShell($cmd)
    {
        $out = @shell_exec($cmd);
        return $out ? trim(preg_replace('/\s+/', ' ', $out)) : null;
    }

    /**
     * Get Asterisk version (e.g. "21.2.0") via core show version.
     * Uses syshelper daemon so www-data does not need sudo (daemon runs privileged).
     */
    private function getAsteriskRelease($asterisk)
    {
        $check = shell_exec('/bin/ps -e 2>/dev/null | /bin/grep asterisk | /bin/grep -v grep');
        if (!$check) {
            return null;
        }
        $cmd = $asterisk . " -rx 'core show version' 2>/dev/null";
        [$ver, $err] = pbx3_request_syscmd($cmd);
        if ($err !== null || $ver === null || $ver === '') {
            return null;
        }
        $ver = trim($ver);
        if (preg_match('/Asterisk\s+([^\s]+)/', $ver, $m)) {
            $raw = $m[1];
            // Return only major.minor.patch (e.g. 20.6.0 from 20.6.0~dfsg+~cs6.13.40431414-2build5)
            if (preg_match('/^(\d+\.\d+\.\d+)/', $raw, $v)) {
                return $v[1];
            }
            return $raw;
        }
        return strlen($ver) ? $ver : null;
    }

    /**
     * Get local IPv4 (same logic as pbx3 NetHelperClass::get_localIPV4):
     * if globals.staticipv4 is set use that, else first inet from default/UP interface.
     */
    private function getLocalIpV4()
    {
        try {
            $row = DB::table('globals')->first();
            if ($row && !empty($row->staticipv4)) {
                return trim($row->staticipv4);
            }
        } catch (\Throwable $e) {
            // fall through to shell
        }
        $firstUp = trim(shell_exec("ip addr 2>/dev/null | grep UP | grep -v 'lo:' | head -1") ?: '');
        if ($firstUp === '' || !preg_match('/\d+:\s*(\w+):?/', $firstUp, $m)) {
            return null;
        }
        $iface = $m[1];
        $out = shell_exec("ip addr show dev " . escapeshellarg($iface) . " 2>/dev/null");
        if ($out && preg_match('/inet\s+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/\d{1,2}/', $out, $m)) {
            return $m[1];
        }
        return null;
    }
}
