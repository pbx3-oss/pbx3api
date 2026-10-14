<?php

namespace App\Support;

use App\Models\Extension;

/**
 * Tenant support line-test WebRTC row (SPA Phase 2).
 * Marker: description = system:line-test. Hidden from default Extensions index.
 */
final class LineTestExtension
{
    public const DESCRIPTION_MARKER = 'system:line-test';

    public const DISPLAY_DESC = 'Line quality test';

    /**
     * Preferred dialable by ext_len — not all-9s (999 is emergency in UK/IE/etc.).
     * 3→981, 4→9801, 5→98001. Sub-min lengths normalize via ExtLenPolicy (→981).
     */
    public static function preferredPkey(int $extLen): string
    {
        $extLen = ExtLenPolicy::normalize($extLen);

        return match ($extLen) {
            2 => '98',
            3 => '981',
            4 => '9801',
            5 => '98001',
            default => str_pad('98', $extLen, '0', STR_PAD_RIGHT),
        };
    }

    public static function isSystemLineTest(?Extension $extension): bool
    {
        if ($extension === null) {
            return false;
        }

        return self::descriptionIsMarker($extension->description ?? null);
    }

    public static function descriptionIsMarker(mixed $description): bool
    {
        return trim((string) $description) === self::DESCRIPTION_MARKER;
    }

    /**
     * Free dialable in the tenant's ext_len namespace.
     * Prefer 981 / 9801 / 98001 (avoid emergency 999…); if taken, walk down.
     */
    public static function allocatePkey(string $clusterShortuid, int $extLen): ?string
    {
        $extLen = ExtLenPolicy::normalize($extLen);
        $start = (int) self::preferredPkey($extLen);
        for ($n = $start; $n >= 0; $n--) {
            $pkey = str_pad((string) $n, $extLen, '0', STR_PAD_LEFT);
            if (! Extension::where('cluster', $clusterShortuid)->where('pkey', $pkey)->exists()) {
                return $pkey;
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Extension>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Extension>
     */
    public static function scopeExcludeSystem($query)
    {
        return $query->where(function ($w) {
            $w->whereNull('description')
                ->orWhere('description', '!=', self::DESCRIPTION_MARKER);
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Extension>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Extension>
     */
    public static function scopeOnlySystem($query, string $clusterShortuid)
    {
        return $query
            ->where('cluster', $clusterShortuid)
            ->where('description', self::DESCRIPTION_MARKER)
            ->where('device', 'WebRTC');
    }
}
