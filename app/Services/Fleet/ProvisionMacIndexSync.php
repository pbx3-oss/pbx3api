<?php

namespace App\Services\Fleet;

use App\Models\Sysglobal;
use Illuminate\Support\Facades\Log;

/**
 * C3 — dual-write extension MAC → catalog MAC index when Gatekeeper is configured.
 *
 * Solo (no PBX3_GATEKEEPER_*): no-op.
 * Fleet: claim/clear must succeed for assign/clear; conflict → 409.
 *
 * @see pbx3/workingdocs/PROVISIONING_SERVER_REQUIREMENTS.md §6
 */
final class ProvisionMacIndexSync
{
    public function __construct(
        private readonly GatekeeperCatalogClient $client,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * After local MAC assign/change: claim catalog row for this home.
     *
     * @throws \RuntimeException on conflict (409) or gatekeeper failure
     */
    public function claim(string $mac, string $tenantShortuid): void
    {
        if (! $this->client->isConfigured()) {
            return;
        }

        $instanceId = $this->instanceIdOrFail();
        $tenant = strtolower(trim($tenantShortuid));
        if ($tenant === '') {
            throw new \RuntimeException('Cannot claim MAC: tenant shortuid missing', 422);
        }

        try {
            $this->client->claimMac($mac, $tenant, $instanceId);
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            Log::warning('provision MAC catalog claim failed', [
                'mac' => $mac,
                'tenant' => $tenant,
                'instance_id' => $instanceId,
                'error' => $e->getMessage(),
                'code' => $code,
            ]);
            if ($code === 409) {
                throw new \RuntimeException(
                    'MAC already claimed on another fleet instance; clear the existing binding first',
                    409,
                    $e
                );
            }
            throw new \RuntimeException(
                'MAC not saved to fleet catalog ('.$e->getMessage()
                .'). Fix Gatekeeper access or clear PBX3_GATEKEEPER_* for solo.',
                $code >= 400 && $code < 600 ? $code : 503,
                $e
            );
        }
    }

    /**
     * After local MAC clear: drop catalog row.
     *
     * @throws \RuntimeException when configured and clear fails
     */
    public function clear(string $mac): void
    {
        if (! $this->client->isConfigured()) {
            return;
        }

        try {
            $this->client->clearMac($mac);
        } catch (\Throwable $e) {
            Log::warning('provision MAC catalog clear failed', [
                'mac' => $mac,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                'MAC clear not synced to fleet catalog ('.$e->getMessage()
                .'). Fix Gatekeeper access or clear PBX3_GATEKEEPER_* for solo.',
                503,
                $e
            );
        }
    }

    private function instanceIdOrFail(): string
    {
        $id = trim((string) (Sysglobal::query()->where('pkey', 'global')->value('id') ?? ''));
        if ($id === '') {
            throw new \RuntimeException(
                'Cannot sync MAC to fleet catalog: globals.id missing',
                503
            );
        }

        return $id;
    }
}
