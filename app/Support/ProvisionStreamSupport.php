<?php

namespace App\Support;

/**
 * B4 provision site-fragment helpers (System files + Customer rows).
 */
class ProvisionStreamSupport
{
    public static function streamsDir(): string
    {
        $env = getenv('PBX3_PROVISION_STREAMS');
        if ($env !== false && trim((string) $env) !== '') {
            return rtrim(trim((string) $env), '/');
        }

        return '/opt/pbx3/provisioning/streams';
    }

    /** @return list<string> */
    public static function systemNames(?string $dir = null): array
    {
        $dir = $dir !== null ? $dir : self::streamsDir();
        if (! is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir.'/'.$f;
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }
            if (preg_match('/\.[LFP]key$/i', $f)) {
                continue;
            }
            $names[] = $f;
        }
        sort($names, SORT_STRING);

        return $names;
    }

    public static function isSystemName(string $name, ?string $dir = null): bool
    {
        return in_array($name, self::systemNames($dir), true);
    }

    public static function readSystemBody(string $name, ?string $dir = null): ?string
    {
        $dir = $dir !== null ? $dir : self::streamsDir();
        if ($name === '' || preg_match('/\.\.|[\\\\\\/]/', $name)) {
            return null;
        }
        $path = rtrim($dir, '/').'/'.$name;
        if (! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        return $raw === false ? null : $raw;
    }

    public static function validatePkey(?string $pkey): ?string
    {
        $pkey = trim((string) $pkey);
        if ($pkey === '' || strlen($pkey) > 128) {
            return null;
        }
        if (! preg_match('/^[\w.\-]+$/', $pkey)) {
            return null;
        }
        if (preg_match('/\.[LFP]key$/i', $pkey)) {
            return null;
        }

        return $pkey;
    }

    /** Soft-warn lines that look like hardcoded secrets (non-$ values). @return list<string> */
    public static function secretLineWarnings(string $body): array
    {
        $warnings = [];
        foreach (explode("\n", $body) as $i => $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || preg_match('/^\s*[;#]/', $line)) {
                continue;
            }
            if (preg_match('/(secret|password|passwd|http_pass|user_pass|ldap\.password|ldap_password|ADMIN_PASS|USER_PASS)\s*[:=]\s*(?!\$)(\S+)/i', $line)) {
                $warnings[] = 'Line '.($i + 1).': looks like a hardcoded secret — prefer $… symbolics gated by sndcreds.';
            }
        }

        return $warnings;
    }

    /**
     * Parse #INCLUDE names from an extension provision stream (order preserved).
     *
     * @return list<string>
     */
    public static function parseIncludes(string $provision): array
    {
        $names = [];
        foreach (explode("\n", $provision) as $line) {
            $line = preg_replace("/\r/", '', $line);
            if (preg_match('/^[;#]INCLUDE\s*([\w_\-\.\/\(\)\s]*)\s*$/', $line, $m)) {
                $names[] = trim($m[1]);
            }
        }

        return $names;
    }

    /**
     * Soft warnings for extension save (miss INCLUDE; Customer/`site.*` before first System).
     *
     * @param  list<string>  $systemNames
     * @param  list<string>  $customerNames  pkeys for this cluster
     * @return list<string>
     */
    public static function extensionIncludeWarnings(string $provision, array $systemNames, array $customerNames): array
    {
        $warnings = [];
        $includes = self::parseIncludes($provision);
        $systemSet = array_fill_keys($systemNames, true);
        $customerSet = array_fill_keys($customerNames, true);

        $seenSystem = false;
        foreach ($includes as $name) {
            if (preg_match('/\.[LFP]key$/i', $name)) {
                continue;
            }
            $isSystem = isset($systemSet[$name]);
            $isCustomer = isset($customerSet[$name]);
            if (! $isSystem && ! $isCustomer) {
                $warnings[] = "Unresolved #INCLUDE {$name} (not System or Customer for this tenant).";
            }
            $isSiteish = $isCustomer || str_starts_with($name, 'site.');
            if ($isSiteish && ! $seenSystem) {
                $warnings[] = "#INCLUDE {$name} appears before the first System INCLUDE — prefer System stock first, then site.…";
            }
            if ($isSystem) {
                $seenSystem = true;
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Count extensions in cluster whose provision stream #INCLUDEs $name exactly.
     */
    public static function refcount(string $cluster, string $name): int
    {
        $cluster = trim($cluster);
        $name = trim($name);
        if ($cluster === '' || $name === '') {
            return 0;
        }

        $rows = \Illuminate\Support\Facades\DB::table('ipphone')
            ->where('cluster', $cluster)
            ->whereNotNull('provision')
            ->where('provision', '!=', '')
            ->pluck('provision');

        $n = 0;
        $re = '/^[;#]INCLUDE\s+'.preg_quote($name, '/').'\s*$/m';
        foreach ($rows as $provision) {
            if (preg_match($re, (string) $provision)) {
                $n++;
            }
        }

        return $n;
    }
}
