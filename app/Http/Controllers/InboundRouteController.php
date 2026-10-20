<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\InboundRoute;
use App\Models\RouteProfile;
use App\Models\RouteProfileLine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class InboundRouteController extends Controller
{
    use EnforcesClusterScope;

    /** DiD/CLiD number: digits, optional leading +, Asterisk pattern _[XZN.!]+, or special s|i|t.
     *  Lab/fleet inbound often stores +E.164 after SBC normalize (see EGRESS_PLUS_E164_WIRE.md). */
    private const PKEY_EXTENSION_REGEX = '/^(\+?\d+|_[XZN.!]+|[sit])$/';

    /** Technology / DDI type: DiD, CLiD, Class (dropdown). */
    private const TECHNOLOGY_VALUES = 'DiD,CLiD,Class';

    // inroutes table. Exclude id, shortuid, z_*. Not updateable: host, iaxreg, password, peername, pjsipreg, register, trunkname, username.
    private $updateableColumns = [
        'pkey' => ['regex:' . self::PKEY_EXTENSION_REGEX],
        'active' => 'in:YES,NO',
        'alertinfo' => 'string|nullable',
        'callback' => 'string|nullable',
        'callerid' => 'string|nullable',
        'closeroute' => 'string|nullable',
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
        'devicerec' => 'in:None,Inbound,default',
        // #8c: DISA dropped; CALLBACK hidden/future — only empty/null/None.
        'disa' => ['nullable', 'string', function ($attribute, $value, $fail) {
            if ($value === null || $value === '' || strcasecmp((string) $value, 'None') === 0) {
                return;
            }
            $fail('DISA and CALLBACK are not supported on inbound routes.');
        }],
        'disapass' => ['nullable', 'string', function ($attribute, $value, $fail) {
            if ($value === null || $value === '') {
                return;
            }
            $fail('DISA password is not supported; leave empty.');
        }],
        'entry_dest' => 'string|nullable',
        'inprefix' => 'string|nullable',
        'match' => 'string|nullable',
        'moh' => 'in:YES,NO',
        'openroute' => 'string|nullable',
        'privileged' => 'string|nullable',
        'route_profile' => 'string|nullable',
        'swoclip' => 'in:YES,NO',
        'tag' => 'string|nullable',
        'technology' => 'in:' . self::TECHNOLOGY_VALUES,
        'transform' => 'string|nullable',
    ];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

/**
 * Return InboundRoute index in pkey order asc.
 * Instance schema uses inroutes table (DDI/CLID); trunks are in trunks table.
 *
 * @return \Illuminate\Support\Collection
 */
    public function index () {

    	return $this->applyClusterScope(InboundRoute::query())->orderBy('pkey','asc')->get();
    }

    /** Export inbound routes list as PDF. Same dataset as index with tenant_pkey resolved. */
    public function exportPdf()
    {
        $inboundroutes = $this->applyClusterScope(InboundRoute::query())->orderBy('pkey', 'asc')->get();
        attach_tenant_pkey_to_collection($inboundroutes);
        return Pdf::loadView('exports.inboundroutes-pdf', ['inboundroutes' => $inboundroutes])
            ->setPaper('a4', 'landscape')
            ->download('inbound-routes.pdf');
    }

/**
 * Return named extension model instance
 * 
 * @param  Extension
 * @return extension object
 */
    public function show (InboundRoute $inboundroute) {

    	$this->assertModelClusterAllowed($inboundroute);
    	return response()->json($inboundroute, 200);
    }

/**
 * Create a new Did instance
 * 
 * @param  Request
 * @return New Did
 */
    public function save(Request $request) {

        $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $this->normalizeInboundRouteRouteJsonScalars($request);

        // Open destination required; closed defaults to open (BLF / timer baseline).
        $open = trim((string) $request->input('openroute', ''));
        $closed = trim((string) $request->input('closeroute', ''));
        if ($this->isNoneDestination($closed)) {
            $closed = $open;
            $request->merge(['closeroute' => $closed]);
        }

// validate (pkey + technology from dropdown; technology is DB column)
        $rules = array_merge($this->updateableColumns, [
            'pkey' => ['required', 'regex:' . self::PKEY_EXTENSION_REGEX],
            'technology' => 'required|in:' . self::TECHNOLOGY_VALUES,
            'cluster' => 'required|exists:cluster,pkey',
            'openroute' => 'required|string|max:128',
        ]);

        $inboundroute = new InboundRoute;
        $inboundroute->openroute = 'None';
        $inboundroute->closeroute = 'None';

        $messages = [
            'pkey.regex' => 'Number must be digits (optional leading + for E.164), pattern _XZN.! (e.g. _2XXX), or special s/i/t.',
            'openroute.required' => 'Open destination is required.',
        ];
        $validator = Validator::make($request->all(), $rules, $messages);
        $validator->setAttributeNames([
            'pkey' => 'Number (DiD/CLiD)',
            'openroute' => 'Open destination',
        ]);

        $validator->after(function ($validator) use ($request, $inboundroute, $clusterShortuid, $open) {
            // Check if key exists within tenant (cluster); DB stores shortuid
            if ($inboundroute->where('pkey', '=', $request->input('pkey'))->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'Duplicate number in this tenant.');
            }
            // Reject single "0" — not a valid DiD/CLiD
            $pkey = trim((string) $request->input('pkey', ''));
            if ($pkey === '0') {
                $validator->errors()->add('pkey', 'Number cannot be a single 0.');
            }
            if ($this->isNoneDestination($open)) {
                $validator->errors()->add('openroute', 'Open destination is required (cannot be None).');
            }
            $this->validateRouteProfile($validator, $request, $clusterShortuid);
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        move_request_to_model($request, $inboundroute, $this->updateableColumns);
        $inboundroute->cluster = $clusterShortuid;
        // Set pkey from request (may be "0" — valid DiD/CLiD; don't use empty() here)
        $inboundroute->pkey = trim((string) $request->input('pkey', ''));

        $inboundroute->openroute = $open;
        $inboundroute->closeroute = $this->isNoneDestination($closed) ? $open : $closed;
        $this->normalizeRouteProfileAndEntry($request, $inboundroute);

        if (empty($inboundroute->trunkname)) {
            $inboundroute->trunkname = $inboundroute->pkey;
        }

        $inboundroute->id = generate_ksuid();
        $inboundroute->shortuid = generate_shortuid();
        $inboundroute->technology = $request->input('technology', 'DiD');

        try {
            DB::transaction(function () use ($inboundroute, $clusterShortuid) {
                $profileShortuid = trim((string) ($inboundroute->route_profile ?? ''));
                if ($profileShortuid === '') {
                    $profileShortuid = $this->createSeededRouteProfile(
                        $clusterShortuid,
                        $inboundroute->pkey,
                        $inboundroute->openroute,
                        $inboundroute->closeroute
                    );
                    $inboundroute->route_profile = $profileShortuid;
                } else {
                    $this->ensureProfileModeLines(
                        $profileShortuid,
                        $clusterShortuid,
                        $inboundroute->openroute,
                        $inboundroute->closeroute
                    );
                }
                $inboundroute->save();
                set_commit_dirty();
            });
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $inboundroute->fresh();
    }



/**
 * @param  Request
 * @param  InboundRoute
 * @return json response
 */
    public function update(Request $request, InboundRoute $inboundroute) {
        $this->assertModelClusterAllowed($inboundroute);

        $this->normalizeInboundRouteRouteJsonScalars($request);

        $validator = Validator::make($request->all(), $this->updateableColumns, [
            'pkey.regex' => 'Number must be digits (optional leading + for E.164), pattern _XZN.! (e.g. _2XXX), or special s/i/t.',
        ]);
        $validator->setAttributeNames(['pkey' => 'Number (DiD/CLiD)']);

        $validator->after(function ($validator) use ($request, $inboundroute) {
            $newPkey = $request->has('pkey') ? trim((string) $request->input('pkey', '')) : null;
            $clusterShortuid = $inboundroute->cluster;
            if (cluster_identifier_to_shortuid($request->input('cluster')) !== null) {
                $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
                $this->assertClusterAllowed($clusterShortuid);
            }
            if ($newPkey !== null && $newPkey !== $inboundroute->pkey) {
                if (InboundRoute::where('pkey', $newPkey)->where('cluster', $clusterShortuid)->where('id', '!=', $inboundroute->id)->exists()) {
                    $validator->errors()->add('pkey', 'Duplicate number in this tenant.');
                }
            }
            if ($request->has('pkey')) {
                $pkey = trim((string) $request->input('pkey', ''));
                if ($pkey === '0') {
                    $validator->errors()->add('pkey', 'Number cannot be a single 0.');
                }
            }
            $this->validateRouteProfile($validator, $request, (string) $clusterShortuid);
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        move_request_to_model($request, $inboundroute, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $this->assertClusterAllowed($clusterShortuid);
            $inboundroute->cluster = $clusterShortuid;
        }
        if ($request->has('technology')) {
            $inboundroute->technology = $request->input('technology');
        }

        if ($request->has('openroute') && (trim((string) $request->input('openroute', '')) === '' || $request->input('openroute') === null)) {
            $inboundroute->openroute = 'None';
        }
        if ($request->has('closeroute') && (trim((string) $request->input('closeroute', '')) === '' || $request->input('closeroute') === null)) {
            $inboundroute->closeroute = 'None';
        }
        $this->normalizeRouteProfileAndEntry($request, $inboundroute);
        if ($request->has('pkey')) {
            $inboundroute->pkey = trim((string) $request->input('pkey', ''));
        }

        // store the model if it has changed — update by id only (tenant-safe)
        try {
            if ($inboundroute->isDirty()) {
                $id = $inboundroute->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'InboundRoute id is missing'], 409);
                }
                $dirty = $inboundroute->getDirty();
                InboundRoute::where('id', $id)->update($dirty);
                $inboundroute->syncOriginal();
                set_commit_dirty();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($inboundroute, 200);
        
    } 


/**
 * Delete  InboundRoute instance
 * @param  InboundRoute
 * @return 204
 */
    public function delete(InboundRoute $inboundroute) {
        $this->assertModelClusterAllowed($inboundroute);
        $inboundroute->delete();
        set_commit_dirty();

        return response()->json(null, 204);
    }

    /**
     * JSON may decode numeric destination keys (e.g. extension 201) as int/float; string validation fails.
     */
    private function normalizeInboundRouteRouteJsonScalars(Request $request): void
    {
        foreach (['openroute', 'closeroute', 'entry_dest'] as $field) {
            if (! $request->exists($field)) {
                continue;
            }
            $v = $request->input($field);
            if (is_int($v) || is_float($v)) {
                $request->merge([$field => (string) $v]);
            }
        }
    }

    private function validateRouteProfile($validator, Request $request, string $clusterShortuid): void
    {
        if (! $request->has('route_profile')) {
            return;
        }
        $rp = trim((string) $request->input('route_profile', ''));
        if ($rp === '' || strcasecmp($rp, 'None') === 0) {
            return;
        }
        if (! RouteProfile::belongsToCluster($rp, $clusterShortuid)) {
            $validator->errors()->add(
                'route_profile',
                'Route profile not found or belongs to another tenant.'
            );
        }
    }

    private function normalizeRouteProfileAndEntry(Request $request, InboundRoute $inboundroute): void
    {
        if ($request->has('route_profile')) {
            $rp = trim((string) $request->input('route_profile', ''));
            $inboundroute->route_profile = ($rp === '' || strcasecmp($rp, 'None') === 0) ? null : $rp;
        }
        if ($request->has('entry_dest')) {
            $ed = trim((string) $request->input('entry_dest', ''));
            $inboundroute->entry_dest = ($ed === '' || strcasecmp($ed, 'None') === 0) ? null : $ed;
        }
    }

    private function isNoneDestination(?string $dest): bool
    {
        $t = trim((string) $dest);

        return $t === '' || strcasecmp($t, 'None') === 0;
    }

    /**
     * Create a tenant profile with open (+ closed) lines for a new DID.
     */
    private function createSeededRouteProfile(
        string $clusterShortuid,
        string $didPkey,
        string $openDest,
        string $closedDest
    ): string {
        $profile = new RouteProfile;
        $profile->id = generate_ksuid();
        $profile->shortuid = generate_shortuid();
        $profile->pkey = $profile->shortuid;
        $profile->cluster = $clusterShortuid;
        $profile->name = 'DID '.$didPkey;
        $profile->default_mode = 'open';
        $profile->description = 'Auto-created for inbound '.$didPkey;
        $profile->save();
        set_commit_dirty();

        $this->insertProfileLine($profile->shortuid, $clusterShortuid, 'open', $openDest);
        $this->insertProfileLine($profile->shortuid, $clusterShortuid, 'closed', $closedDest);

        return $profile->shortuid;
    }

    /**
     * Seed missing open/closed lines on an existing profile (does not overwrite).
     */
    private function ensureProfileModeLines(
        string $profileShortuid,
        string $clusterShortuid,
        string $openDest,
        string $closedDest
    ): void {
        $hasOpen = RouteProfileLine::where('profile', $profileShortuid)
            ->whereRaw('lower(mode) = ?', ['open'])
            ->exists();
        $hasClosed = RouteProfileLine::where('profile', $profileShortuid)
            ->whereRaw('lower(mode) = ?', ['closed'])
            ->exists();

        if (! $hasOpen) {
            $this->insertProfileLine($profileShortuid, $clusterShortuid, 'open', $openDest);
        }
        if (! $hasClosed) {
            $this->insertProfileLine($profileShortuid, $clusterShortuid, 'closed', $closedDest);
        }
    }

    private function insertProfileLine(
        string $profileShortuid,
        string $clusterShortuid,
        string $mode,
        string $destination
    ): void {
        $row = new RouteProfileLine;
        $row->id = generate_ksuid();
        $row->shortuid = generate_shortuid();
        $row->profile = $profileShortuid;
        $row->cluster = $clusterShortuid;
        $row->mode = $mode;
        $row->destination = $destination;
        $row->save();
        set_commit_dirty();
    }
}
