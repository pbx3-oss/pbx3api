<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\ProvisionStream;
use App\Support\ProvisionStreamSupport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

/**
 * B4 — tenant Provision streams: System (package RO) + Customer (CRUD).
 */
class ProvisionStreamController extends Controller
{
    use EnforcesClusterScope;

    private $updateableColumns = [
        'cluster' => 'exists:cluster,pkey',
        'body' => 'string|nullable',
        'notes' => 'string|nullable',
    ];

    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index(Request $request)
    {
        $clusterShortuid = $this->resolveClusterParam($request);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Query cluster= is required.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $system = [];
        foreach (ProvisionStreamSupport::systemNames() as $name) {
            $system[] = [
                'name' => $name,
                'source' => 'system',
                'shortuid' => null,
                'updated_at' => null,
                'refcount' => ProvisionStreamSupport::refcount($clusterShortuid, $name),
            ];
        }

        $customer = [];
        $rows = $this->applyClusterScope(ProvisionStream::query()->where('cluster', $clusterShortuid))
            ->orderBy('pkey')
            ->get();
        foreach ($rows as $row) {
            $customer[] = [
                'name' => $row->pkey,
                'source' => 'customer',
                'shortuid' => $row->shortuid,
                'updated_at' => $row->updated_at,
                'notes' => $row->notes,
                'refcount' => ProvisionStreamSupport::refcount($clusterShortuid, $row->pkey),
            ];
        }

        return response()->json([
            'cluster' => $clusterShortuid,
            'streams' => array_merge($system, $customer),
        ]);
    }

    public function show(Request $request, string $name)
    {
        $clusterShortuid = $this->resolveClusterParam($request);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Query cluster= is required.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $name = rawurldecode($name);
        $customer = ProvisionStream::where('cluster', $clusterShortuid)->where('pkey', $name)->first();
        if ($customer) {
            $this->assertModelClusterAllowed($customer);

            return response()->json([
                'name' => $customer->pkey,
                'source' => 'customer',
                'shortuid' => $customer->shortuid,
                'id' => $customer->id,
                'cluster' => $customer->cluster,
                'body' => $customer->body,
                'notes' => $customer->notes,
                'updated_at' => $customer->updated_at,
                'refcount' => ProvisionStreamSupport::refcount($clusterShortuid, $customer->pkey),
                'read_only' => false,
            ]);
        }

        if (ProvisionStreamSupport::isSystemName($name)) {
            $body = ProvisionStreamSupport::readSystemBody($name);
            if ($body === null) {
                return response()->json(['Error' => 'System stream unreadable'], 404);
            }

            return response()->json([
                'name' => $name,
                'source' => 'system',
                'shortuid' => null,
                'id' => null,
                'cluster' => $clusterShortuid,
                'body' => $body,
                'notes' => null,
                'updated_at' => null,
                'refcount' => ProvisionStreamSupport::refcount($clusterShortuid, $name),
                'read_only' => true,
            ]);
        }

        return response()->json(['Error' => 'Not found'], 404);
    }

    public function save(Request $request)
    {
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $pkey = ProvisionStreamSupport::validatePkey($request->input('pkey'));
        if ($pkey === null) {
            return response()->json([
                'pkey' => ['Name must be 1–128 chars [A-Za-z0-9._-]; not *.Fkey / *.Lkey / *.Pkey.'],
            ], 422);
        }
        if (ProvisionStreamSupport::isSystemName($pkey)) {
            return response()->json([
                'pkey' => ['Name collides with a System stream — choose a Customer name (prefer site.…).'],
            ], 409);
        }

        $body = (string) ($request->input('body') ?? '');
        if (strlen($body) > 65536) {
            return response()->json(['body' => ['Body exceeds 64 KiB.']], 422);
        }

        if (ProvisionStream::where('cluster', $clusterShortuid)->where('pkey', $pkey)->exists()) {
            return response()->json(['pkey' => ['A Customer fragment with that name already exists.']], 409);
        }

        $row = new ProvisionStream;
        $row->cluster = $clusterShortuid;
        $row->pkey = $pkey;
        $row->body = $body;
        $row->notes = $request->input('notes');
        $row->id = generate_ksuid();
        $row->shortuid = generate_shortuid();
        $row->updated_at = gmdate('Y-m-d\TH:i:s\Z');
        $this->stampAudit($row, true);

        try {
            $row->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        $warnings = ProvisionStreamSupport::secretLineWarnings($body);
        if (trim($body) === '') {
            $warnings[] = 'Body is empty — confirm intentional.';
        }

        return response()->json([
            'name' => $row->pkey,
            'source' => 'customer',
            'shortuid' => $row->shortuid,
            'id' => $row->id,
            'cluster' => $row->cluster,
            'body' => $row->body,
            'notes' => $row->notes,
            'updated_at' => $row->updated_at,
            'refcount' => 0,
            'read_only' => false,
            'warnings' => $warnings,
        ], 201);
    }

    public function update(Request $request, string $name)
    {
        $clusterShortuid = $this->resolveClusterParam($request);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Query cluster= is required.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $name = rawurldecode($name);
        $row = ProvisionStream::where('cluster', $clusterShortuid)->where('pkey', $name)->first();
        if (! $row) {
            if (ProvisionStreamSupport::isSystemName($name)) {
                return response()->json(['Error' => 'System streams are read-only'], 403);
            }

            return response()->json(['Error' => 'Not found'], 404);
        }
        $this->assertModelClusterAllowed($row);

        $validator = Validator::make($request->all(), [
            'body' => 'string|nullable',
            'notes' => 'string|nullable',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($request->has('body')) {
            $body = (string) $request->input('body');
            if (strlen($body) > 65536) {
                return response()->json(['body' => ['Body exceeds 64 KiB.']], 422);
            }
            $row->body = $body;
        }
        if ($request->has('notes')) {
            $row->notes = $request->input('notes');
        }
        $row->updated_at = gmdate('Y-m-d\TH:i:s\Z');
        $this->stampAudit($row, false);

        try {
            if ($row->isDirty()) {
                $id = $row->id;
                if ($id === null || $id === '') {
                    return response()->json(['Error' => 'Missing id'], 409);
                }
                ProvisionStream::where('id', $id)->update($row->getDirty());
                $row->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        $warnings = ProvisionStreamSupport::secretLineWarnings((string) $row->body);
        if (trim((string) $row->body) === '') {
            $warnings[] = 'Body is empty — confirm intentional.';
        }

        return response()->json([
            'name' => $row->pkey,
            'source' => 'customer',
            'shortuid' => $row->shortuid,
            'id' => $row->id,
            'cluster' => $row->cluster,
            'body' => $row->body,
            'notes' => $row->notes,
            'updated_at' => $row->updated_at,
            'refcount' => ProvisionStreamSupport::refcount($clusterShortuid, $row->pkey),
            'read_only' => false,
            'warnings' => $warnings,
        ]);
    }

    public function delete(Request $request, string $name)
    {
        $clusterShortuid = $this->resolveClusterParam($request);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Query cluster= is required.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $name = rawurldecode($name);
        if (ProvisionStreamSupport::isSystemName($name)) {
            return response()->json(['Error' => 'System streams are read-only'], 403);
        }

        $row = ProvisionStream::where('cluster', $clusterShortuid)->where('pkey', $name)->first();
        if (! $row) {
            return response()->json(['Error' => 'Not found'], 404);
        }
        $this->assertModelClusterAllowed($row);

        $force = $request->boolean('force');
        $refcount = ProvisionStreamSupport::refcount($clusterShortuid, $row->pkey);
        if ($refcount > 0 && ! $force) {
            return response()->json([
                'Error' => 'Fragment is still #INCLUDE’d by one or more extensions',
                'refcount' => $refcount,
            ], 409);
        }

        $row->delete();

        return response()->json(['ok' => true, 'refcount' => $refcount]);
    }

    public function copyFromSystem(Request $request)
    {
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $from = trim((string) $request->input('from'));
        $to = ProvisionStreamSupport::validatePkey($request->input('to'));
        if ($from === '' || ! ProvisionStreamSupport::isSystemName($from)) {
            return response()->json(['from' => ['from must be an existing System stream name.']], 422);
        }
        if ($to === null) {
            return response()->json([
                'to' => ['to must be a valid Customer name (prefer site.<name>).'],
            ], 422);
        }
        if ($to === $from || ProvisionStreamSupport::isSystemName($to)) {
            return response()->json(['to' => ['Customer name must not equal a System filename.']], 409);
        }

        $body = ProvisionStreamSupport::readSystemBody($from);
        if ($body === null) {
            return response()->json(['from' => ['System stream unreadable.']], 404);
        }

        if (ProvisionStream::where('cluster', $clusterShortuid)->where('pkey', $to)->exists()) {
            return response()->json(['to' => ['A Customer fragment with that name already exists.']], 409);
        }

        $row = new ProvisionStream;
        $row->cluster = $clusterShortuid;
        $row->pkey = $to;
        $row->body = $body;
        $row->notes = 'Copied from System '.$from;
        $row->id = generate_ksuid();
        $row->shortuid = generate_shortuid();
        $row->updated_at = gmdate('Y-m-d\TH:i:s\Z');
        $this->stampAudit($row, true);
        $row->save();

        return response()->json([
            'name' => $row->pkey,
            'source' => 'customer',
            'shortuid' => $row->shortuid,
            'id' => $row->id,
            'cluster' => $row->cluster,
            'body' => $row->body,
            'notes' => $row->notes,
            'updated_at' => $row->updated_at,
            'refcount' => 0,
            'read_only' => false,
            'warnings' => ProvisionStreamSupport::secretLineWarnings($body),
        ], 201);
    }

    private function resolveClusterParam(Request $request): ?string
    {
        $raw = $request->query('cluster', $request->input('cluster'));
        if ($raw === null || $raw === '') {
            return null;
        }

        return cluster_identifier_to_shortuid($raw);
    }

    private function stampAudit(ProvisionStream $row, bool $isCreate): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $user = request()->user('sanctum') ?? auth('sanctum')->user();
        $who = $user && $user->email ? (string) $user->email : 'system';
        if ($isCreate) {
            $row->z_created = $now;
        }
        $row->z_updated = $now;
        $row->z_updater = $who;
    }
}
