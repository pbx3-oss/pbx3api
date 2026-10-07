<?php

namespace App\Services\Provision;

/**
 * Soft-fill ipphone.provision (+ Poly sndcreds) from IEEE/manuf.txt OUI.
 * Does not write devicevendor / device (S12).
 *
 * @see pbx3/workingdocs/PROVISIONING_SERVER_REQUIREMENTS.md §4.4 / §5
 */
final class ProvisionOuiMapper
{
    public function __construct(
        private readonly ?string $manufPath = null,
    ) {}

    public function manufPath(): string
    {
        if ($this->manufPath !== null && $this->manufPath !== '') {
            return $this->manufPath;
        }

        $env = env('PBX3_MANUF_TXT');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return '/opt/pbx3/cache/manuf.txt';
    }

    /**
     * Manufacturer string from manuf.txt for the MAC's OUI, or null.
     */
    public function vendorFromMac(string $mac): ?string
    {
        $oui = $this->ouiPrefix($mac);
        if ($oui === null) {
            return null;
        }

        $path = $this->manufPath();
        if (! is_readable($path)) {
            return null;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return null;
        }

        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // "AA:BB:CC Vendor Name..."
                if (preg_match('/^([0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2})\s+(.+)$/', $line, $m) !== 1) {
                    continue;
                }
                if (strcasecmp($m[1], $oui) === 0) {
                    return trim($m[2]);
                }
            }
        } finally {
            fclose($fh);
        }

        return null;
    }

    /**
     * Default provision body for a manuf.txt vendor string, or null (no auto-fill).
     */
    public function defaultProvisionForVendor(?string $vendor): ?string
    {
        $grain = $this->streamGrain($vendor);
        if ($grain === null) {
            return null;
        }

        return match ($grain) {
            'yealink' => "#INCLUDE yealink.Extension\n#INCLUDE yealink.udp\n",
            'snom' => "#INCLUDE snom.Extension\n#INCLUDE snom.udp\n",
            'fanvil' => "#INCLUDE fanvil.Extension\n#INCLUDE fanvil.udp\n",
            'poly' => "#INCLUDE poly.Extension\n#INCLUDE poly.udp\n",
            'panasonic' => "#INCLUDE Panasonic\n#INCLUDE panasonic.udp\n",
            default => null,
        };
    }

    /**
     * Preferred sndcreds when soft-filling for this vendor, or null (leave alone).
     * Poly → Always; others → null (create path still sets Once separately).
     */
    public function defaultSndcredsForVendor(?string $vendor): ?string
    {
        return $this->streamGrain($vendor) === 'poly' ? 'Always' : null;
    }

    /**
     * Apply soft-fill onto current provision / sndcreds values.
     *
     * @return array{provision?: string, sndcreds?: string}
     */
    public function softFillPatch(string $mac, ?string $currentProvision, ?string $currentSndcreds): array
    {
        if (trim((string) $currentProvision) !== '') {
            return [];
        }

        $vendor = $this->vendorFromMac($mac);
        $provision = $this->defaultProvisionForVendor($vendor);
        if ($provision === null) {
            return [];
        }

        $patch = ['provision' => $provision];

        $wantSnd = $this->defaultSndcredsForVendor($vendor);
        if ($wantSnd !== null) {
            $cur = trim((string) $currentSndcreds);
            // Bump empty/Once → Always; leave explicit No (and Already Always) alone.
            if ($cur === '' || strcasecmp($cur, 'Once') === 0) {
                $patch['sndcreds'] = $wantSnd;
            }
        }

        return $patch;
    }

    private function streamGrain(?string $vendor): ?string
    {
        if ($vendor === null || trim($vendor) === '') {
            return null;
        }
        $v = strtolower($vendor);

        if (str_contains($v, 'yealink')) {
            return 'yealink';
        }
        if (str_contains($v, 'snom')) {
            return 'snom';
        }
        if (str_contains($v, 'fanvil')) {
            return 'fanvil';
        }
        // Poly (HP) and legacy Polycom — word "poly" alone or polycom
        if (str_contains($v, 'polycom') || preg_match('/\bpoly\b/', $v) === 1) {
            return 'poly';
        }
        if (str_contains($v, 'panasonic')) {
            return 'panasonic';
        }

        // Grandstream / Gigaset / Cisco / unknown → no auto-fill
        return null;
    }

    private function ouiPrefix(string $mac): ?string
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $mac) ?? '';
        if (strlen($hex) < 6) {
            return null;
        }
        $hex = strtoupper(substr($hex, 0, 6));

        return substr($hex, 0, 2).':'.substr($hex, 2, 2).':'.substr($hex, 4, 2);
    }
}
