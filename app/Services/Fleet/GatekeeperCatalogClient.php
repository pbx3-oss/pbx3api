<?php

namespace App\Services\Fleet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Node → Gatekeeper catalog writes (sitename ≡ label). Rule 9/10: via Gatekeeper, not browser IAM.
 *
 * @see pbx3/workingdocs/FLEET_NAMING_LOCK.md
 */
class GatekeeperCatalogClient
{
    public function isConfigured(): bool
    {
        $base = config('pbx3_fleet.gatekeeper_url');
        $token = config('pbx3_fleet.gatekeeper_token');

        return is_string($base) && trim($base) !== ''
            && is_string($token) && trim($token) !== '';
    }

    /**
     * PATCH /api/v1/instances/{id} — at least label.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function patchInstance(string $instanceId, array $patch): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Gatekeeper catalog client not configured');
        }

        $id = trim($instanceId);
        if ($id === '') {
            throw new \InvalidArgumentException('Instance id required');
        }

        $base = rtrim((string) config('pbx3_fleet.gatekeeper_url'), '/');
        $verify = (bool) config('pbx3_fleet.gatekeeper_http_verify', true);

        $response = Http::withToken((string) config('pbx3_fleet.gatekeeper_token'))
            ->acceptJson()
            ->withOptions(['verify' => $verify])
            ->timeout(20)
            ->patch("{$base}/api/v1/instances/".rawurlencode($id), $patch);

        if (! $response->successful()) {
            Log::warning('gatekeeper catalog patch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'instance_id' => $id,
            ]);
            throw new \RuntimeException(
                'Gatekeeper catalog update failed: HTTP '.$response->status(),
                $response->status()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * C3 — claim MAC in catalog index (fleet provision routing).
     *
     * @return array<string, mixed>
     */
    public function claimMac(string $mac, string $tenantShortuid, string $instanceId): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Gatekeeper catalog client not configured');
        }

        $base = rtrim((string) config('pbx3_fleet.gatekeeper_url'), '/');
        $verify = (bool) config('pbx3_fleet.gatekeeper_http_verify', true);

        $response = Http::withToken((string) config('pbx3_fleet.gatekeeper_token'))
            ->acceptJson()
            ->withOptions(['verify' => $verify])
            ->timeout(20)
            ->post("{$base}/api/v1/mac-index/claim", [
                'mac' => $mac,
                'tenant_shortuid' => $tenantShortuid,
                'instance_id' => $instanceId,
            ]);

        if (! $response->successful()) {
            Log::warning('gatekeeper mac-index claim failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'mac' => $mac,
            ]);
            throw new \RuntimeException(
                'Gatekeeper MAC claim failed: HTTP '.$response->status(),
                $response->status()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * C3 — clear MAC from catalog index.
     *
     * @return array<string, mixed>
     */
    public function clearMac(string $mac): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Gatekeeper catalog client not configured');
        }

        $base = rtrim((string) config('pbx3_fleet.gatekeeper_url'), '/');
        $verify = (bool) config('pbx3_fleet.gatekeeper_http_verify', true);

        $response = Http::withToken((string) config('pbx3_fleet.gatekeeper_token'))
            ->acceptJson()
            ->withOptions(['verify' => $verify])
            ->timeout(20)
            ->post("{$base}/api/v1/mac-index/clear", [
                'mac' => $mac,
            ]);

        if (! $response->successful()) {
            Log::warning('gatekeeper mac-index clear failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'mac' => $mac,
            ]);
            throw new \RuntimeException(
                'Gatekeeper MAC clear failed: HTTP '.$response->status(),
                $response->status()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
