<?php

namespace App\Services\Provision;

use App\Support\ProvisionStreamSupport;
use PDO;

/**
 * Dry-run provision expand for SPA preview (§4.7.1).
 * Same INCLUDE + substitute path as the phone listener; secrets obfuscated;
 * no sndcreds flip, audit, or last_provisioned bump.
 */
final class ProvisionPreviewService
{
    public function ensureKernelLoaded(): void
    {
        if (function_exists('pbx3_provision_expand')) {
            return;
        }

        $candidates = [
            env('PBX3_PROVISION_KERNEL'),
            '/opt/pbx3/php/classes/ProvisionKernel.php',
            base_path('../pbx3/pbx3-1/opt/pbx3/php/classes/ProvisionKernel.php'),
        ];
        foreach ($candidates as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            $path = trim($path);
            if (is_readable($path)) {
                require_once $path;

                return;
            }
        }

        throw new \RuntimeException('ProvisionKernel.php not found — tip pbx3 or set PBX3_PROVISION_KERNEL');
    }

    /**
     * @param  object|array  $phone  ipphone-shaped row (pkey, shortuid, passwd, cluster, macaddr, provision, …)
     * @param  array<string, mixed>  $globals
     * @return array{ok: bool, body: string, include_misses: list<string>, error?: string}
     */
    public function preview(object|array $phone, ?string $draftProvision = null, array $globals = []): array
    {
        $this->ensureKernelLoaded();

        $p = is_array($phone) ? $phone : (array) $phone;
        $raw = $draftProvision !== null ? (string) $draftProvision : (string) ($p['provision'] ?? '');
        if (trim($raw) === '') {
            return [
                'ok' => false,
                'body' => '',
                'include_misses' => [],
                'error' => 'empty_provision',
            ];
        }

        $streamsDir = ProvisionStreamSupport::streamsDir();
        $pdo = $this->openPdo();

        $hosts = pbx3_provision_resolve_hosts($pdo, $globals);
        $extra = [
            'localip' => $hosts['localip'],
            'outbound' => $hosts['outbound'],
            'outbound_enable' => $hosts['outbound_enable'],
            'outbound_hostport' => $hosts['outbound_hostport'],
            'provurl' => $hosts['provurl'],
        ];
        if (! empty($hosts['registrar'])) {
            $extra['registrar'] = $hosts['registrar'];
        }

        $globals = pbx3_provision_merge_cluster_device_passwords($pdo, $phone, $globals);
        $extra = pbx3_provision_merge_phone_hosts($pdo, $phone, $globals, $extra);

        // Expand as Always so secret stanzas appear, then obfuscate for the SPA.
        $loop = [];
        $misses = [];
        $cluster = isset($p['cluster']) ? (string) $p['cluster'] : 'default';
        $expanded = pbx3_provision_expand($raw."\n", true, $streamsDir, $loop, $pdo, $cluster, $misses);
        $vars = pbx3_provision_vars_from_phone($phone, $globals, $extra);
        $body = pbx3_provision_substitute($expanded, $vars);

        return [
            'ok' => true,
            'body' => pbx3_provision_obfuscate_for_audit($body),
            'include_misses' => array_values(array_unique($misses)),
        ];
    }

    private function openPdo(): ?PDO
    {
        $path = (string) config('database.connections.sqlite.database', '');
        if ($path === '' || $path === ':memory:') {
            $path = (string) env('DB_DATABASE', '/opt/pbx3/db/sqlite.db');
        }
        if ($path === '' || $path === ':memory:' || ! is_readable($path)) {
            return null;
        }
        try {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
