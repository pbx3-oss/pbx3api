<?php

namespace App\Support;

/**
 * Home provision URL helpers (Phase B1).
 * Solo / Phase A: https://{instance-fqdn}:41363/provisioning/{mac}.cfg
 * Fleet phone-facing provision.{apex} is Phase C — SPA still shows home URL for ops curl.
 */
class ProvisionUrl
{
    public const PORT = 41363;

    /** Normalize to 12 lowercase hex or null. */
    public static function normalizeMac(?string $mac): ?string
    {
        if ($mac === null || $mac === '') {
            return null;
        }
        $hex = strtolower(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
        if (strlen($hex) !== 12 || !ctype_xdigit($hex)) {
            return null;
        }
        return $hex;
    }

    public static function forHome(?string $fqdn, ?string $mac, int $port = self::PORT): ?string
    {
        $mac = self::normalizeMac($mac);
        $fqdn = $fqdn !== null ? trim($fqdn) : '';
        if ($mac === null || $fqdn === '') {
            return null;
        }
        return 'https://' . $fqdn . ':' . $port . '/provisioning/' . $mac . '.cfg';
    }
}
