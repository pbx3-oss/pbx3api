<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Directory\FleetPreflightService;
use App\Services\Tenant\PortableUserMobility;
use App\Services\Tenant\TenantMobilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Node-side mobility HTTP API for the fleet gatekeeper (S8.10 §13.3.1).
 * Auth: fleet service bearer — not Sanctum admin.
 */
class FleetMobilityController extends Controller
{
    public function preflight(FleetPreflightService $preflight): JsonResponse
    {
        $checks = $preflight->run();

        return response()->json([
            'ok' => $preflight->allPassed(),
            'checks' => $checks,
        ]);
    }

    /** Gatekeeper fleet probe — AMI Egress qualify (Avail/Unavail). */
    public function egressQualify(\App\Services\Fleet\FleetPostureService $posture): JsonResponse
    {
        $live = $posture->egressQualifyLive();

        return response()->json([
            'fleet' => $posture->isFleetNode(),
            'egress_trunk' => (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress'),
            'state' => (string) ($live['state'] ?? 'Unknown'),
            'rtt_ms' => $live['rtt_ms'] ?? null,
            'latency' => $live['latency'] ?? null,
        ]);
    }

    public function export(Request $request, string $tenant, TenantMobilityService $mobility): JsonResponse
    {
        $presignedUrl = (string) $request->input('presigned_put_url', '');
        if ($presignedUrl === '') {
            return response()->json(['message' => 'presigned_put_url is required'], 422);
        }

        try {
            $result = $mobility->export($tenant, [
                'include_recordings' => (bool) $request->boolean('include_recordings'),
                // Fleet move: detach portable users from source after packing (import recreates).
                'detach_portable_users' => $request->boolean('detach_portable_users', true),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $zipPath = $result['zip_path'];
        try {
            $this->uploadFile($presignedUrl, $zipPath);
        } catch (\Throwable $e) {
            Log::error('fleet export upload failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Export created but upload to staging failed: '.$e->getMessage(),
                'zip_path' => $zipPath,
                'manifest' => $result['manifest'],
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'manifest' => $result['manifest'],
            'uploaded' => true,
        ]);
    }

    public function import(Request $request, TenantMobilityService $mobility): JsonResponse
    {
        $presignedUrl = (string) $request->input('presigned_get_url', '');
        if ($presignedUrl === '') {
            return response()->json(['message' => 'presigned_get_url is required'], 422);
        }

        $tmp = sys_get_temp_dir().'/pbx3fleet-import-'.bin2hex(random_bytes(4)).'.zip';
        try {
            $this->downloadFile($presignedUrl, $tmp);
            $result = $mobility->import($tmp, [
                'replace' => (bool) $request->boolean('replace'),
                'skip_media' => (bool) $request->boolean('skip_media'),
            ]);
        } catch (\Throwable $e) {
            @unlink($tmp);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        @unlink($tmp);

        return response()->json([
            'ok' => true,
            'result' => $result,
        ]);
    }

    public function commit(SysCommandController $sys)
    {
        return $sys->commit();
    }

    public function certificatesSync(Request $request, CertificateController $certs)
    {
        return $certs->sync($request);
    }

    /**
     * Fleet-first tenant create — Gatekeeper pushes cluster create onto the home node.
     * Shares TenantController::save (shortuid/FQDN assignment). Auth: fleet.token.
     */
    public function storeTenant(Request $request, TenantController $tenants)
    {
        return $tenants->save($request);
    }

    /**
     * T1 — per-table wipe blast-radius counts before Fleet Delete confirm (informational).
     */
    public function wipePreflight(string $tenant, TenantMobilityService $mobility): JsonResponse
    {
        $model = (new Tenant)->resolveRouteBinding($tenant);
        if ($model === null) {
            return response()->json(['message' => "Tenant not found: {$tenant}"], 404);
        }
        if ($model->pkey === 'default') {
            return response()->json(['message' => 'Cannot delete default tenant'], 409);
        }

        try {
            $counts = $mobility->countTenantData($model);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'wipe' => $counts,
        ]);
    }

    public function destroyTenant(string $tenant, TenantMobilityService $mobility, PortableUserMobility $portableUsers): JsonResponse
    {
        $model = (new Tenant)->resolveRouteBinding($tenant);
        if ($model === null) {
            return response()->json(['message' => "Tenant not found: {$tenant}"], 404);
        }
        if ($model->pkey === 'default') {
            return response()->json(['message' => 'Cannot delete default tenant'], 409);
        }

        $id = $model->id;
        $shortuid = (string) $model->shortuid;
        // T3 — same park file cleanup as Sanctum TenantController::delete
        pbx3_delete_park_asterisk_instances($shortuid);
        $mobility->destroyTenantData($model);
        $usersRemoved = $portableUsers->removeOrStripForTenant($shortuid);
        pbx3_update_fqdn_inline_optional();

        return response()->json([
            'ok' => true,
            'deleted' => $id,
            'portable_users' => $usersRemoved,
        ]);
    }

    /**
     * Instance identity for Fleet Register probe (#5m).
     * Gatekeeper calls with fleet service token; SPA never sees the token.
     */
    public function identity(): JsonResponse
    {
        $g = \App\Models\Sysglobal::query()->where('pkey', 'global')->first();
        if (! $g) {
            return response()->json(['message' => 'System globals not found'], 404);
        }

        $id = trim((string) ($g->id ?? ''));
        $fqdn = trim((string) ($g->fqdn ?? ''));
        $shortuid = trim((string) ($g->shortuid ?? ''));
        if ($shortuid === '' && $fqdn !== '' && str_contains($fqdn, '.')) {
            $shortuid = explode('.', $fqdn, 2)[0];
        }
        $sitename = trim((string) ($g->sitename ?? ''));

        if ($id === '' || $fqdn === '') {
            return response()->json([
                'message' => 'globals.id or globals.fqdn empty — finish home install before Register',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'id' => $id,
            'shortuid' => $shortuid,
            'fqdn' => $fqdn,
            'sitename' => $sitename !== '' ? $sitename : null,
        ]);
    }

    /**
     * Fleet-owned friendly Name → globals.sitename (FLEET_NAMING_LOCK).
     * Sanctum cannot change sitename on fleet nodes; Gatekeeper PATCHes label then calls this.
     */
    public function putSitename(Request $request): JsonResponse
    {
        $sitename = trim((string) $request->input('sitename', ''));
        // Empty allowed → Home falls back to shortuid
        $sysglobal = \App\Models\Sysglobal::first();
        if (! $sysglobal) {
            return response()->json(['message' => 'System globals not found'], 404);
        }
        $sysglobal->sitename = $sitename !== '' ? $sitename : null;
        $sysglobal->save();

        return response()->json([
            'ok' => true,
            'sitename' => $sysglobal->sitename,
        ]);
    }

    /**
     * C2 — upsert managed dialalias (source=cohort). No genAst; caller uses POST /fleet/commit.
     */
    public function upsertDialAlias(Request $request, \App\Services\Fleet\ManagedDialAliasService $svc): JsonResponse
    {
        try {
            $result = $svc->upsert(is_array($request->all()) ? $request->all() : []);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 422;
            }

            return response()->json(['message' => $e->getMessage()], $code);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 409;
            }

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json($result, $result['action'] === 'created' ? 201 : 200);
    }

    /**
     * C2/C3 — list dialaliases for one calling tenant (prune / reconcile).
     */
    public function listDialAliases(Request $request): JsonResponse
    {
        $cluster = cluster_identifier_to_shortuid((string) $request->query('cluster', ''));
        if ($cluster === null) {
            $raw = strtolower(trim((string) $request->query('cluster', '')));
            if ($raw === '' || ! preg_match('/^[a-z0-9]+$/', $raw)) {
                return response()->json(['message' => 'cluster query required (tenant shortuid)'], 422);
            }
            $cluster = $raw;
        }

        $rows = \App\Models\DialAlias::query()
            ->where('cluster', $cluster)
            ->orderBy('pkey')
            ->get();

        return response()->json([
            'cluster' => $cluster,
            'dialaliases' => $rows,
        ]);
    }

    /**
     * C2 — delete dialalias. Default managed_only; pass managed_only:false to prune lab rows.
     */
    public function deleteDialAlias(Request $request, \App\Services\Fleet\ManagedDialAliasService $svc): JsonResponse
    {
        try {
            $result = $svc->delete(is_array($request->all()) ? $request->all() : []);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 422;
            }

            return response()->json(['message' => $e->getMessage()], $code);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 404;
            }

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json($result, 200);
    }

    private function uploadFile(string $url, string $path): void
    {
        $body = file_get_contents($path);
        if ($body === false) {
            throw new \RuntimeException("Cannot read export zip: {$path}");
        }

        $response = Http::withBody($body, 'application/zip')
            ->timeout(300)
            ->put($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Presigned PUT failed HTTP '.$response->status());
        }
    }

    private function downloadFile(string $url, string $destPath): void
    {
        $response = Http::timeout(300)->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('Presigned GET failed HTTP '.$response->status());
        }
        if (file_put_contents($destPath, $response->body()) === false) {
            throw new \RuntimeException("Cannot write import zip: {$destPath}");
        }
    }
}
