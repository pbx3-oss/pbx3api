<?php

namespace App\Services\Ops;

/**
 * Parse Asterisk security (or legacy messages) lines for failed REGISTER / InvalidPassword.
 */
final class AsteriskAuthFailureParser
{
    /**
     * @return array{extension: string, source_ip: string}|null
     */
    public static function parseLine(string $line): ?array
    {
        // chan_sip: Registration from '...' failed for 'IP:port' - Wrong password
        if (preg_match(
            "/Registration from\\s+(.+?)\\s+failed for\\s+['\"](\\d{1,3}(?:\\.\\d{1,3}){3})(?::\\d+)?['\"].*Wrong password/i",
            $line,
            $m
        )) {
            return [
                'extension' => self::extensionFromUri($m[1]),
                'source_ip' => $m[2],
            ];
        }

        // PJSIP: Request 'REGISTER' from '...' failed for 'IP:port' ... Failed to authenticate
        if (preg_match(
            "/Request\\s+['\"]REGISTER['\"]\\s+from\\s+(.+?)\\s+failed for\\s+['\"](\\d{1,3}(?:\\.\\d{1,3}){3})(?::\\d+)?['\"].*Failed to authenticate/i",
            $line,
            $m
        )) {
            return [
                'extension' => self::extensionFromUri($m[1]),
                'source_ip' => $m[2],
            ];
        }

        // SecurityEvent=InvalidPassword ... AccountID="1102" ... RemoteAddress="IPV4/UDP/1.2.3.4/5060"
        if (stripos($line, 'SecurityEvent="InvalidPassword"') !== false
            || stripos($line, 'SecurityEvent=InvalidPassword') !== false
        ) {
            $ext = '';
            $ip = '';
            if (preg_match('/AccountID="([^"]+)"/', $line, $m)) {
                $ext = $m[1];
            } elseif (preg_match('/AccountID=([^,\\s]+)/', $line, $m)) {
                $ext = $m[1];
            }
            if (preg_match('/RemoteAddress="[^"]*?(\\d{1,3}(?:\\.\\d{1,3}){3})/', $line, $m)) {
                $ip = $m[1];
            } elseif (preg_match('/RemoteAddress=[^,]*?(\\d{1,3}(?:\\.\\d{1,3}){3})/', $line, $m)) {
                $ip = $m[1];
            }
            if ($ip !== '') {
                return [
                    'extension' => $ext !== '' ? $ext : '(unknown)',
                    'source_ip' => $ip,
                ];
            }
        }

        return null;
    }

    private static function extensionFromUri(string $from): string
    {
        // sip:1102@host or "Name" <sip:1102@host>
        if (preg_match('/sip:([^@>;\\s]+)/i', $from, $m)) {
            return $m[1];
        }
        $from = trim($from, "\"' ");

        return $from !== '' ? $from : '(unknown)';
    }
}
