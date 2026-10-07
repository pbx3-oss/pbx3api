<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\Extension;
use App\Support\ExtLenPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use App\Models\IpPhoneCosOpen;
use App\Models\IpPhoneCosClosed;
use App\Models\ClassOfService;
use App\Models\CosProfile;
use App\CustomClasses\Ami;
use App\Support\LineTestExtension;
use App\Support\ProvisionUrl;
use App\Support\ProvisionStreamSupport;
use App\Services\Fleet\ProvisionMacIndexSync;
use App\Services\Provision\ProvisionOuiMapper;
use App\Models\ProvisionStream;
use Illuminate\Support\Facades\Schema;

class ExtensionController extends Controller
{
	use EnforcesClusterScope;


	// ipphone table (full_schema.sql). Exclude id, shortuid, z_*. Model guarded: abstimeout, basemacaddr, devicemodel, passwd, etc.
	private $updateableColumns = [
		'active' => 'in:YES,NO',
		'pkey' => 'string|nullable',
		'callbackto' => 'in:desk,cell',
		'callerid' => 'string|nullable',
		'callmax' => 'integer|nullable',
		'cellphone' => 'string|nullable',
		'celltwin' => 'in:ON,OFF',
		'cluster' => 'exists:cluster,pkey',
		'cname' => 'string|nullable',
		'desc' => 'nullable|string|max:255',
		'description' => 'string|nullable',
		'device' => 'string|nullable',
		'devicerec' => 'in:default,None,Inbound,Outbound,Both',
		'dvrvmail' => 'exists:ipphone,pkey|nullable',
		'extalert' => 'string|nullable',
		'macaddr' => 'string|nullable',
		'protocol' => 'in:IPV4,IPV6',
		'provision' => 'string|nullable',
		'provisionwith' => 'in:IP,FQDN',
		// §4.3 — Once preferred; Always for vendors that re-fetch secrets every poll (e.g. Poly)
		'sndcreds' => 'in:No,Once,Always',
		'technology' => 'string|nullable',
		'transport' => 'in:udp,tcp,tls,wss',
		'vmailfwd' => 'email|nullable',
		'pjsip_overlay' => 'nullable|string|max:16384',
		// Comma-separated named group tokens; ALL = whole tenant (GenAst → $clst)
		'named_call_group' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9_,.\- ]*$/'],
		'named_pickup_group' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9_,.\- ]*$/'],
		'cos_profile' => 'string|nullable',
	];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

/**
 * Return Extension Index in pkey order asc.
 * Each extension includes tenant_pkey (cluster pkey for display) resolved from cluster table.
 *
 * @return Extensions
 */
    public function index (Request $request) {

    	$query = $this->applyClusterScope(Extension::query());
    	// Hide support line-test WebRTC from customer Extensions list (Phase 2).
    	if (! $request->boolean('include_system')) {
    		LineTestExtension::scopeExcludeSystem($query);
    	}
    	$extensions = $query->orderBy('pkey','asc')->get();

    	// Build cluster id/shortuid/pkey -> tenant pkey map (id = KSUID, shortuid = 8-char, pkey = human-facing)
    	$clusterToPkey = [];
    	try {
    		$rows = DB::table('cluster')->get(['id', 'shortuid', 'pkey']);
    		foreach ($rows as $row) {
    			if (isset($row->id)) {
    				$clusterToPkey[(string) $row->id] = $row->pkey ?? $row->id;
    			}
    			if (isset($row->shortuid)) {
    				$clusterToPkey[(string) $row->shortuid] = $row->pkey ?? $row->shortuid;
    			}
    			if (isset($row->pkey)) {
    				$clusterToPkey[(string) $row->pkey] = $row->pkey;
    			}
    		}
    	} catch (\Throwable $e) {
    		try {
    			$rows = DB::table('cluster')->get(['id', 'pkey']);
    			foreach ($rows as $row) {
    				if (isset($row->id)) {
    					$clusterToPkey[(string) $row->id] = $row->pkey ?? $row->id;
    				}
    				if (isset($row->pkey)) {
    					$clusterToPkey[(string) $row->pkey] = $row->pkey;
    				}
    			}
    		} catch (\Throwable $e2) {
    			$rows = DB::table('cluster')->get(['pkey']);
    			foreach ($rows as $row) {
    				if (isset($row->pkey)) {
    					$clusterToPkey[(string) $row->pkey] = $row->pkey;
    				}
    			}
    		}
    	}

    	foreach ($extensions as $ext) {
    		$cluster = $ext->cluster ?? null;
    		$ext->tenant_pkey = $cluster !== null && $cluster !== ''
    			? ($clusterToPkey[(string) $cluster] ?? $cluster)
    			: $cluster;
    	}
    	return $extensions;
    }

/**
 * Export extensions list as PDF. Same dataset as index() (all extensions, tenant_pkey resolved).
 *
 * @return \Illuminate\Http\Response PDF download
 */
    public function exportPdf()
    {
    	$extensions = $this->index(request());
    	return Pdf::loadView('exports.extensions-pdf', ['extensions' => $extensions])
    		->setPaper('a4', 'landscape')
    		->download('extensions.pdf');
    }

/**
 * Return live PJSIP data (IP, latency) for SIP extensions that are not inactive  (legacy PBX: skip active == NO).
 * Keyed by pkey for merging with list view. Inactive rows use Unknown in the UI (no key here).
 * Requires Asterisk running.
 *
 * @return object keyed by extension pkey, values { ip, latency }
 */
    public function indexLive() {
        set_time_limit(30);
        if (!function_exists('pbx_is_running') || !pbx_is_running()) {
            return Response::json(['message' => 'PBX not running'], 503);
        }
        $extensions = $this->applyClusterScope(Extension::query())
            ->where('technology', 'SIP')
            ->where(function ($q) {
                $q->whereNull('active')->orWhere('active', '<>', 'NO');
            });
        LineTestExtension::scopeExcludeSystem($extensions);
        $extensions = $extensions
            ->orderBy('pkey')
            ->limit(200)
            ->get(['pkey', 'shortuid']);
        $live = [];
        try {
            $amiHandle = get_ami_handle();
            foreach ($extensions as $ext) {
                $endpointId = $ext->shortuid ?? $ext->pkey;
                $live[$ext->pkey] = pjsip_endpoint_live($amiHandle, $endpointId);
            }
            $amiHandle->logout();
        } catch (\Throwable $e) {
            Log::warning('Extensions live data failed', ['error' => $e->getMessage()]);
            return Response::json(['message' => 'Could not fetch live endpoint data'], 503);
        }
        return Response::json($live, 200);
    }

/**
 * Create a new extension (single endpoint). extensionType: SIP | WebRTC.
 * Sets id (ksuid), dvrvmail = pkey. Optional MAC sets ipphone.device via OUI (vendor label).
 *
 * @param  Request  pkey, cluster, desc (name), extensionType (SIP|WebRTC), macaddr (optional for SIP), protocol (IPV4|IPV6)
 * @return New extension
 */
    public function save(Request $request) {
        $all = $request->all();
        $extensionTypeInput = $all['extensionType'] ?? null;
        if (!$extensionTypeInput && isset($all['protocol']) && in_array($all['protocol'], ['SIP', 'WebRTC'], true)) {
            $extensionTypeInput = $all['protocol'];
        }
        // Normalise for validation: if client sent protocol=SIP|WebRTC (old frontend), use ipversion for protocol (IP version)
        $validateInput = array_merge($all, ['extensionType' => $extensionTypeInput]);
        if (isset($validateInput['protocol']) && in_array($validateInput['protocol'], ['SIP', 'WebRTC'], true)) {
            $validateInput['protocol'] = $all['ipversion'] ?? 'IPV4';
        }
        $validator = Validator::make($validateInput, [
            'pkey' => 'required',
            'cluster' => 'required|exists:cluster,pkey',
            'desc' => 'nullable|string|max:255',
            'extensionType' => 'required|in:SIP,WebRTC',
            'macaddr' => 'nullable|regex:/^[0-9a-fA-F]{12}$/',
            'active' => 'nullable|in:YES,NO',
            'transport' => 'nullable|in:udp,tcp,tls,wss',
            'callbackto' => 'nullable|in:desk,cell',
            'callerid' => 'nullable|string|max:255',
            'cellphone' => 'nullable|string|max:255',
            'celltwin' => 'nullable|in:ON,OFF',
            'devicerec' => 'nullable|in:default,None,Inbound,Outbound,Both',
            'protocol' => 'nullable|in:IPV4,IPV6',
            'ipversion' => 'nullable|in:IPV4,IPV6',
            'vmailfwd' => 'nullable|email',
            'named_call_group' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9_,.\- ]*$/'],
            'named_pickup_group' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9_,.\- ]*$/'],
            'cos_profile' => 'nullable|string|max:64',
        ]);

        $validator->after(function ($validator) use ($request, $extensionTypeInput) {
            $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
            if ($clusterShortuid === null && $request->cluster !== null && $request->cluster !== '') {
                $validator->errors()->add('cluster', 'Invalid cluster.');
            }
            if ($clusterShortuid !== null) {
                $extLen = ExtLenPolicy::forClusterIdentifier($clusterShortuid);
                if (! ExtLenPolicy::isValidExtensionPkey($request->pkey, $extLen)) {
                    $validator->errors()->add('pkey', ExtLenPolicy::extensionPkeyValidationMessage($extLen));
                }
            }
            if ($clusterShortuid !== null && Extension::where('pkey', $request->pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('save', 'Duplicate extension - ' . $request->pkey . ' in this tenant.');
            }
            if ($extensionTypeInput === 'SIP' && $request->macaddr) {
                $mac = preg_replace('/[^0-9a-fA-F]/', '', $request->macaddr);
                if ($mac !== '' && Extension::where('macaddr', $mac)->exists()) {
                    $validator->errors()->add('macaddr', 'This MAC already exists.');
                }
            }
            if ($request->has('cos_profile') && $clusterShortuid !== null) {
                $cp = trim((string) $request->input('cos_profile', ''));
                if ($cp !== '' && strcasecmp($cp, 'None') !== 0 && ! CosProfile::belongsToCluster($cp, $clusterShortuid)) {
                    $validator->errors()->add('cos_profile', 'Unknown CoS profile for this tenant.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $pkey = $request->input('pkey');
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);
        $extensionType = $request->input('extensionType') ?: $extensionTypeInput;
        $macaddr = $request->input('macaddr');
        $macaddr = $macaddr !== null && $macaddr !== '' ? preg_replace('/[^0-9a-fA-F]/', '', $macaddr) : null;
        $protocolInput = $request->input('protocol');
        if (!in_array($protocolInput, ['IPV4', 'IPV6'], true)) {
            $protocolInput = $request->input('ipversion', 'IPV4');
        }

        $id = generate_ksuid();
        $shortuid = generate_shortuid();
        $dvrvmail = $pkey;

        $attrs = [
            'id' => $id,
            'shortuid' => $shortuid,
            'pkey' => $pkey,
            'cluster' => $clusterShortuid,
            'dvrvmail' => $dvrvmail,
        ];

        $provisionwith = 'IP';
        try {
            $globals = get_globals();
            if ($globals && isset($globals->fqdnprov) && strtoupper((string) $globals->fqdnprov) === 'YES') {
                $provisionwith = 'FQDN';
            }
        } catch (\Throwable $e) {
            // keep default IP
        }
        $attrs['provisionwith'] = $provisionwith;

        // SPA "Name" → ipphone.desc; default Ext{pkey} when blank (legacy PBX-style)
        $desc = $request->input('desc');
        $attrs['desc'] = ($desc !== null && trim((string) $desc) !== '')
            ? trim((string) $desc)
            : ('Ext' . $pkey);
        if ($request->filled('description')) {
            $attrs['description'] = $request->input('description');
        }

        if ($extensionType === 'SIP') {
            $attrs['transport'] = $request->input('transport', 'udp');
            $attrs['protocol'] = $protocolInput;
            $attrs['technology'] = 'SIP';
            $attrs['provision'] = null;

            if ($macaddr !== null && $macaddr !== '') {
                $attrs['macaddr'] = $macaddr;
            }
            $attrs['device'] = 'General SIP';
        } else {
            $attrs['device'] = 'WebRTC';
            $attrs['transport'] = $request->input('transport', 'wss');
            $attrs['protocol'] = $protocolInput;
            $attrs['technology'] = 'SIP';
            $attrs['provision'] = null;
        }

        if ($request->has('active')) {
            $attrs['active'] = $request->input('active');
        }
        if ($request->has('callbackto')) {
            $attrs['callbackto'] = $request->input('callbackto');
        }
        if ($request->has('callerid')) {
            $attrs['callerid'] = $request->input('callerid') ?: null;
        }
        if ($request->has('cellphone')) {
            $attrs['cellphone'] = $request->input('cellphone') ?: null;
        }
        if ($request->has('celltwin')) {
            $attrs['celltwin'] = $request->input('celltwin');
        }
        if ($request->has('devicerec')) {
            $attrs['devicerec'] = $request->input('devicerec');
        }
        if ($request->has('vmailfwd')) {
            $attrs['vmailfwd'] = $request->input('vmailfwd') ?: null;
        }
        if ($request->has('named_call_group')) {
            $ng = trim((string) $request->input('named_call_group'));
            $attrs['named_call_group'] = $ng !== '' ? $ng : 'ALL';
        } else {
            $attrs['named_call_group'] = 'ALL';
        }
        if ($request->has('named_pickup_group')) {
            $ng = trim((string) $request->input('named_pickup_group'));
            $attrs['named_pickup_group'] = $ng !== '' ? $ng : 'ALL';
        } else {
            $attrs['named_pickup_group'] = 'ALL';
        }
        if ($request->has('cos_profile')) {
            $cp = trim((string) $request->input('cos_profile', ''));
            if ($cp !== '' && strcasecmp($cp, 'None') !== 0) {
                $attrs['cos_profile'] = $cp;
            }
        }

        try {
            $extension = Extension::create($attrs);
        } catch (\Exception $e) {
            Log::warning('Extension create failed', ['error' => $e->getMessage(), 'attrs_keys' => array_keys($attrs)]);
            return response()->json([
                'Error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 409);
        }

        // SIP password: auto-generate 12 chars (passwd not fillable; set via direct update)
        $secretPatch = ['passwd' => ret_password()];
        if ($macaddr !== null && $macaddr !== '' && Schema::hasColumn('ipphone', 'sndcreds')) {
            $secretPatch['sndcreds'] = 'Once';
        }
        Extension::where('id', $extension->id)->update($secretPatch);

        $this->create_default_cos_instances($extension);

        set_commit_dirty();

        if ($macaddr !== null && $macaddr !== '') {
            $tenant = $clusterShortuid ?? cluster_identifier_to_shortuid((string) ($extension->cluster ?? ''));
            try {
                app(ProvisionMacIndexSync::class)->claim($macaddr, (string) $tenant);
            } catch (\RuntimeException $e) {
                Extension::where('id', $extension->id)->update(['macaddr' => null]);
                $code = (int) $e->getCode();
                if ($code < 400 || $code > 599) {
                    $code = 503;
                }

                return response()->json([
                    'macaddr' => [$e->getMessage()],
                    'Error' => $e->getMessage(),
                ], $code);
            }

            // Soft-fill empty provision from OUI (does not touch devicevendor).
            $fresh = $extension->fresh();
            $ouiPatch = app(ProvisionOuiMapper::class)->softFillPatch(
                $macaddr,
                $fresh?->provision,
                $fresh?->sndcreds
            );
            if ($ouiPatch !== []) {
                if (! Schema::hasColumn('ipphone', 'sndcreds')) {
                    unset($ouiPatch['sndcreds']);
                }
                if ($ouiPatch !== []) {
                    Extension::where('id', $extension->id)->update($ouiPatch);
                }
            }
        }

        return response()->json($this->enrichProvisionFields($extension->fresh()), 201);
    }

/**
 * Return named extension instance. Resolves cluster to tenant_pkey for display.
 *
 * @param  Extension
 * @return extension object
 */
    public function show (Extension $extension) {

    	$this->assertModelClusterAllowed($extension);
    	$cluster = $extension->cluster ?? null;
    	if ($cluster !== null && $cluster !== '') {
    		$row = DB::table('cluster')->where('pkey', $cluster)->orWhere('shortuid', $cluster)->orWhere('id', $cluster)->first(['pkey']);
    		$extension->tenant_pkey = $row ? $row->pkey : $cluster;
    	} else {
    		$extension->tenant_pkey = $cluster;
    	}
    	$this->enrichProvisionFields($extension);
    	// Include passwd only for single-extension (detail/edit); not in index/list
    	return $extension->makeVisible('passwd');
    }

/**
 * Return named extension runtime values from the PBX (CFIM, CFBS, ringdelay; for SIP also ip and latency).
 *
 * @param  Extension
 * @return object cfim, cfbs, ringdelay; for SIP extensions also ip, latency
 */
    public function showruntime (Extension $extension) {

        $this->assertModelClusterAllowed($extension);
        $amiHandle = get_ami_handle();
        $key = $this->runtimeAstdbKey($extension);
        $legacyPkey = (string) ($extension->pkey ?? '');

        $rets = [];
        // Prefer shortuid key (what pbx3cagi CFCheck / star-codes use); fall back to legacy pkey.
        $rets['cfim'] = $this->runtimeAstdbGet($amiHandle, 'cfim', $key, $legacyPkey);
        $rets['cfbs'] = $this->runtimeAstdbGet($amiHandle, 'cfbs', $key, $legacyPkey);
        $rets['ringdelay'] = $this->runtimeAstdbGet($amiHandle, 'ringdelay', $key, $legacyPkey);

        if (($extension->technology ?? '') === 'SIP') {
            $live = pjsip_endpoint_live($amiHandle, $extension->shortuid ?? $extension->pkey);
            $rets['ip'] = $live['ip'];
            $rets['latency'] = $live['latency'];
        }

        $amiHandle->logout();

        return Response::json($rets, 200);
    }

/**
 * Standard (open) and After-hours (closed) CoS assignments for an extension.
 * Rules are tenant-scoped; open/closed lists are cos_pkey values present in junction tables.
 */
    public function showcos(Extension $extension)
    {
        $this->assertModelClusterAllowed($extension);
        // Legacy rows may store cluster as pkey; new rows store shortuid. Match either.
        $aliases = cluster_identifier_aliases($extension->cluster);
        if ($aliases === []) {
            $aliases = [(string) $extension->cluster];
        }

        $rules = ClassOfService::whereIn('cluster', $aliases)
            ->orderBy('pkey', 'asc')
            ->get(['pkey', 'cname', 'description', 'active', 'defaultopen', 'defaultclosed']);

        $open = IpPhoneCosOpen::where('ipphone_pkey', $extension->pkey)
            ->whereIn('cluster', $aliases)
            ->orderBy('cos_pkey', 'asc')
            ->pluck('cos_pkey')
            ->values();

        $closed = IpPhoneCosClosed::where('ipphone_pkey', $extension->pkey)
            ->whereIn('cluster', $aliases)
            ->orderBy('cos_pkey', 'asc')
            ->pluck('cos_pkey')
            ->values();

        return Response::json([
            'rules' => $rules,
            'open' => $open,
            'closed' => $closed,
        ], 200);
    }

/**
 * Replace Standard (open) and After-hours (closed) CoS assignments for an extension.
 * Body: { "open": ["rule1", ...], "closed": ["rule2", ...] } — full lists (not a patch).
 */
    public function updatecos(Request $request, Extension $extension)
    {
        $this->assertModelClusterAllowed($extension);
        $validator = Validator::make($request->all(), [
            'open' => 'present|array',
            'open.*' => 'string',
            'closed' => 'present|array',
            'closed.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $aliases = cluster_identifier_aliases($extension->cluster);
        if ($aliases === []) {
            $aliases = [(string) $extension->cluster];
        }
        // Always write junction rows with canonical shortuid.
        $cluster = cluster_identifier_to_shortuid($extension->cluster) ?? (string) $extension->cluster;

        $validPkeys = ClassOfService::whereIn('cluster', $aliases)->pluck('pkey')->all();
        $validSet = array_fill_keys($validPkeys, true);

        $open = array_values(array_unique(array_map('strval', $request->input('open', []))));
        $closed = array_values(array_unique(array_map('strval', $request->input('closed', []))));

        foreach (['open' => $open, 'closed' => $closed] as $field => $list) {
            foreach ($list as $cosPkey) {
                if (!isset($validSet[$cosPkey])) {
                    return response()->json([
                        $field => ["Unknown CoS rule for this tenant: {$cosPkey}"],
                    ], 422);
                }
            }
        }

        try {
            DB::transaction(function () use ($extension, $aliases, $cluster, $open, $closed) {
                IpPhoneCosOpen::where('ipphone_pkey', $extension->pkey)
                    ->whereIn('cluster', $aliases)
                    ->delete();
                IpPhoneCosClosed::where('ipphone_pkey', $extension->pkey)
                    ->whereIn('cluster', $aliases)
                    ->delete();

                foreach ($open as $cosPkey) {
                    IpPhoneCosOpen::create([
                        'ipphone_pkey' => $extension->pkey,
                        'cos_pkey' => $cosPkey,
                        'cluster' => $cluster,
                    ]);
                }
                foreach ($closed as $cosPkey) {
                    IpPhoneCosClosed::create([
                        'ipphone_pkey' => $extension->pkey,
                        'cos_pkey' => $cosPkey,
                        'cluster' => $cluster,
                    ]);
                }
            });
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        set_commit_dirty();
        return $this->showcos($extension);
    }

/**
 * Create a new MAILBOX extension instance
 * 
 * @param  Request
 * @return New extension
 */
    public function mailbox(Request $request) {
        $validator = Validator::make($request->all(), [
            'pkey' => 'required|unique:ipphone,pkey',
            'cluster' => 'required|exists:cluster,pkey',
            'desc' => 'string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
    	
    	$validator = Validator::make($request->all(),[
    		'pkey' => 'required',
    		'cluster' => 'required|exists:cluster,pkey'
    	]);

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

        $validator->after(function ($validator) use ($request) {
//Check if key exists
            if (Extension::where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });        

    	$clusterShortuid = cluster_identifier_to_shortuid($request->post('cluster'));
    	if ($clusterShortuid === null) {
    		return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
    	}
    	try {
    		$extension = Extension::create([
    			'id' => generate_ksuid(),
    			'shortuid' => generate_shortuid(),
    			'pkey' => $request->post('pkey'),
    			'desc' => 'MAILBOX',
    			'device' => 'MAILBOX',
    			'cluster' => $clusterShortuid,
    			]);
    	} catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}
    	Extension::where('id', $extension->id)->update(['passwd' => ret_password()]);
    	return $extension;
	}


/**
 * Create a new unprovisioned extension instance
 * 
 * @param  Request
 * @return New Unprovisioned Extension
 */

    public function unprovisioned(Request $request) {

    	$validator = Validator::make($request->all(),[
    		'pkey' => 'required',
    		'cluster' => 'required|exists:cluster,pkey'
    	]);

        $validator->after(function ($validator) use ($request) {
//Check if key exists
            if (Extension::where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

        $clusterShortuid = cluster_identifier_to_shortuid($request->post('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
    	try {
    		$extension = Extension::create([
    			'id' => generate_ksuid(),
    			'shortuid' => generate_shortuid(),
    			'pkey' => $request->post('pkey'),
    			'desc' => 'Ext' .$request->post('pkey'),
    			'device' => 'General SIP',
    			'cluster' => $clusterShortuid,
    			]);
    	} catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}
    	Extension::where('id', $extension->id)->update(['passwd' => ret_password()]);

// create default Clsss of service contraints

    	$this->create_default_cos_instances($extension);

    	return $extension;
	}

/**
 * Create a new webrtc extension instance
 * 
 * @param  Request
 * @return New webrtc Extension
 */

 public function webrtc(Request $request) {

	$validator = Validator::make($request->all(),[
		'pkey' => 'required',
		'cluster' => 'required|exists:cluster,pkey'
	]);

	$validator->after(function ($validator) use ($request) {
//Check if key exists
		if (Extension::where('pkey','=',$request->pkey)->count()) {
			$validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
			return;
		}                 
	});

	if ($validator->fails()) {
		return response()->json($validator->errors(),422);
	}

	$clusterShortuid = cluster_identifier_to_shortuid($request->post('cluster'));
	if ($clusterShortuid === null) {
		return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
	}

	try {
		$extension = Extension::create([
			'id' => generate_ksuid(),
			'shortuid' => generate_shortuid(),
			'pkey' => $request->post('pkey'),
			'desc' => 'Ext' .$request->post('pkey'),
			'device' => 'WebRTC',
			'transport' => 'wss',
			'cluster' => $clusterShortuid,
			]);
	} catch (\Exception $e) {
		return Response::json(['Error' => $e->getMessage()],409);
	}
	Extension::where('id', $extension->id)->update(['passwd' => ret_password()]);

// create default Class of service contraints

	$this->create_default_cos_instances($extension);

	return $extension;
}	

/**
 * Create a new provisioned extension instance
 * 
 * @param  Request
 * @return New provisioned Extension
 */

	public function provisioned(Request $request) {

    	$validator = Validator::make($request->all(),[
    		'pkey' => 'required',
    		'cluster' => 'required|exists:cluster,pkey',
    		'macaddr' => 'required|regex:/^[0-9a-fA-F]{12}$/'
    	]);

        $validator->after(function ($validator) use ($request) {
//Check if key exists
            if (Extension::where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });

    	$device = 'General SIP';

    	$validator->after(function ($validator) use ($request) {
    			if (Extension::where('macaddr','=',$request->post('macaddr'))->count()) {
    				$validator->errors()->add('macaddr', "This MAC already exists in the DB! " . $request->post('macaddr'));
    			}
		});

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

        $clusterShortuid = cluster_identifier_to_shortuid($request->post('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

    	try {
        	$extension = Extension::create([
        		'id' => generate_ksuid(),
        		'shortuid' => generate_shortuid(),
        		'pkey' => $request->post('pkey'),
        		'provision' => null,
        		'device' => $device,
        		'technology' => 'SIP',
        		'cluster' => $clusterShortuid,
        		'macaddr' => $request->post('macaddr'),
        		]);
        } catch (\Exception $e) {
   			return Response::json(['Error' => $e->getMessage()],409);
    	}
    	$legacyPatch = ['passwd' => ret_password()];
        $ouiPatch = app(ProvisionOuiMapper::class)->softFillPatch(
            (string) $request->post('macaddr'),
            null,
            Schema::hasColumn('ipphone', 'sndcreds') ? 'Once' : null
        );
        if (isset($ouiPatch['provision'])) {
            $legacyPatch['provision'] = $ouiPatch['provision'];
        }
        if (isset($ouiPatch['sndcreds']) && Schema::hasColumn('ipphone', 'sndcreds')) {
            $legacyPatch['sndcreds'] = $ouiPatch['sndcreds'];
        } elseif (Schema::hasColumn('ipphone', 'sndcreds')) {
            $legacyPatch['sndcreds'] = 'Once';
        }
    	Extension::where('id', $extension->id)->update($legacyPatch);

// create default Clsss of service contraints

    	$this->create_default_cos_instances($extension);

		return response()->json($extension->fresh(), 201);
		
    }  

    /**
     * Update extension. Uses Request + Validator only (no Form Request). Pkey uniqueness enforced in after() when pkey is changed.
     *
     * @param  Request $request
     * @param  Extension $extension
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Extension $extension) {
        $this->assertModelClusterAllowed($extension);
        // Validation: merge updateableColumns with required pkey/cluster and rules that match former ExtensionRequest
        $rules = array_merge($this->updateableColumns, [
            'pkey' => 'required',
            'cluster' => 'required|exists:cluster,pkey',
            'macaddr' => ['nullable', 'regex:/^(?:[0-9a-fA-F]{12}|([0-9a-fA-F]{2}[:-]){5}[0-9a-fA-F]{2})$/'],
            'technology' => 'nullable|in:SIP,IAX2,DiD,CLiD,Class',
        ]);
        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $extension) {
            $pkeySubmitted = $request->input('pkey');
            $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
            $cluster = $clusterShortuid ?? $request->input('cluster') ?? $extension->cluster;
            if ($cluster !== null && $pkeySubmitted !== null) {
                $extLen = ExtLenPolicy::forClusterIdentifier($cluster);
                if (! ExtLenPolicy::isValidExtensionPkey($pkeySubmitted, $extLen)) {
                    $validator->errors()->add('pkey', ExtLenPolicy::extensionPkeyValidationMessage($extLen));
                }
            }
            $currentPkey = (string) $extension->getAttribute('pkey');
            if ($pkeySubmitted !== null && (string) $pkeySubmitted !== $currentPkey) {
                if ($cluster !== null && Extension::where('pkey', $pkeySubmitted)->where('cluster', $cluster)->where('id', '!=', $extension->id)->exists()) {
                    $validator->errors()->add('pkey', 'The pkey has already been taken in this cluster.');
                }
            }
            if ($request->has('cos_profile')) {
                $cp = trim((string) $request->input('cos_profile', ''));
                if ($cp !== '' && $cp !== 'None' && $cluster !== null) {
                    $clst = cluster_identifier_to_shortuid((string) $cluster) ?? (string) $cluster;
                    if (! CosProfile::belongsToCluster($cp, $clst)) {
                        $validator->errors()->add('cos_profile', 'Unknown CoS profile for this tenant.');
                    }
                }
            }
        });
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $originalMac = $extension->getOriginal('macaddr');
        $originalMac = $originalMac !== null && $originalMac !== '' ? preg_replace('/[^0-9a-fA-F]/', '', $originalMac) : null;

        foreach ($request->all() as $key => $value) {
            if (array_key_exists($key, $this->updateableColumns)) {
                if ($key === 'cluster') {
                    $resolved = cluster_identifier_to_shortuid($value) ?? trim((string) $value);
                    $this->assertClusterAllowed($resolved !== '' ? $resolved : null);
                    $extension->cluster = $resolved;
                } elseif ($key === 'pjsip_overlay') {
                    if ($value === null || (is_string($value) && trim($value) === '')) {
                        $extension->pjsip_overlay = null;
                    } else {
                        $extension->pjsip_overlay = is_string($value) ? trim($value) : $value;
                    }
                } elseif ($key === 'named_call_group' || $key === 'named_pickup_group') {
                    $ng = is_string($value) ? trim($value) : $value;
                    $extension->$key = ($ng === null || $ng === '') ? 'ALL' : $ng;
                } elseif ($key === 'cos_profile') {
                    $cp = is_string($value) ? trim($value) : $value;
                    $extension->cos_profile = ($cp === null || $cp === '' || strcasecmp((string) $cp, 'None') === 0)
                        ? null
                        : $cp;
                } else {
                    $extension->$key = is_string($value) ? trim($value) : $value;
                }
            }
        }

        // Velocity V5 sets z_updater=velocity when auto-blocking. Clearing Active→YES
        // is an operator override — drop the velocity stamp so SPA honesty resets.
        $wasVelocity = strcasecmp((string) $extension->getOriginal('z_updater'), 'velocity') === 0;
        if ($wasVelocity && strtoupper((string) $extension->active) === 'YES') {
            $extension->z_updater = 'system';
        }

        $newMac = $extension->macaddr;
        $newMac = $newMac !== null && $newMac !== '' ? preg_replace('/[^0-9a-fA-F]/', '', $newMac) : null;

        $macAdded = $originalMac === null && $newMac !== null;
        $macChanged = $originalMac !== null && $newMac !== null && $originalMac !== $newMac;
        $macRemoved = $originalMac !== null && $newMac === null;

        if ($macAdded || $macChanged) {
            if (strlen($newMac) !== 12 || !preg_match('/^[0-9a-fA-F]{12}$/', $newMac)) {
                return response()->json(['macaddr' => ['MAC must be 12 hex characters.']], 422);
            }
            $exists = Extension::where('macaddr', $newMac)->where('id', '!=', $extension->id)->exists();
            if ($exists) {
                return response()->json(['macaddr' => ['This MAC already exists.']], 422);
            }
            $extension->macaddr = $newMac;
            // MAC is best-effort inventory only — do not rewrite device type from OUI.
            // Soft-fill empty provision stream from manuf.txt OUI (not devicevendor).
            $ouiPatch = app(ProvisionOuiMapper::class)->softFillPatch(
                $newMac,
                $extension->provision,
                $extension->sndcreds ?? null
            );
            if (isset($ouiPatch['provision'])) {
                $extension->provision = $ouiPatch['provision'];
            }
            if (isset($ouiPatch['sndcreds']) && Schema::hasColumn('ipphone', 'sndcreds')) {
                $extension->sndcreds = $ouiPatch['sndcreds'];
            }
        } elseif ($macRemoved) {
            $extension->macaddr = null;
            // Leave device (WebRTC|MAILBOX|General SIP) unchanged.
            // Do not clear provision on MAC clear.
        }

        // C3 — catalog MAC index before local persist (conflict must not leave half-state)
        if ($macAdded || $macChanged || $macRemoved) {
            $sync = app(ProvisionMacIndexSync::class);
            try {
                if ($macAdded || $macChanged) {
                    $tenant = cluster_identifier_to_shortuid((string) ($extension->cluster ?? ''))
                        ?? (string) $extension->cluster;
                    $sync->claim((string) $newMac, $tenant);
                }
                if (($macChanged || $macRemoved) && $originalMac !== null) {
                    $sync->clear($originalMac);
                }
            } catch (\RuntimeException $e) {
                $code = (int) $e->getCode();
                if ($code < 400 || $code > 599) {
                    $code = 503;
                }

                return response()->json([
                    'macaddr' => [$e->getMessage()],
                    'Error' => $e->getMessage(),
                ], $code);
            }
        }

        try {
            if ($extension->isDirty()) {
                $id = $extension->id;
                if ($id === null || $id === '') {
                    return response()->json(['Error' => 'Extension id is missing'], 409);
                }
                $dirty = $extension->getDirty();
                Extension::where('id', $id)->update($dirty);
                $extension->syncOriginal();
                set_commit_dirty();
            }
        } catch (\Exception $e) {
            return response()->json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($this->enrichProvisionFields($extension->fresh()), 200);
    }

/**
 * Resolve or create the tenant's hidden support line-test WebRTC (SPA Phase 2).
 * Body: cluster (tenant pkey / shortuid / id). Returns extension with passwd visible.
 * New rows need Commit before REGISTER works.
 *
 * @return \Illuminate\Http\JsonResponse
 */
    public function ensureLineTest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cluster' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $this->assertClusterAllowed($clusterShortuid);

        $existing = LineTestExtension::scopeOnlySystem(Extension::query(), $clusterShortuid)->first();
        if ($existing) {
            return response()->json($this->lineTestPayload($existing, false), 200);
        }

        $extLen = ExtLenPolicy::forClusterIdentifier($clusterShortuid);
        $pkey = LineTestExtension::allocatePkey($clusterShortuid, $extLen);
        if ($pkey === null) {
            return response()->json([
                'message' => 'No free extension number in this tenant for the line-test WebRTC.',
            ], 409);
        }

        $id = generate_ksuid();
        $shortuid = generate_shortuid();
        $attrs = [
            'id' => $id,
            'shortuid' => $shortuid,
            'pkey' => $pkey,
            'cluster' => $clusterShortuid,
            'dvrvmail' => $pkey,
            'desc' => LineTestExtension::DISPLAY_DESC,
            'description' => LineTestExtension::DESCRIPTION_MARKER,
            'device' => 'WebRTC',
            'transport' => 'udp',
            'protocol' => 'IPV4',
            'technology' => 'SIP',
            'provision' => null,
            'active' => 'YES',
            'named_call_group' => 'ALL',
            'named_pickup_group' => 'ALL',
        ];

        $provisionwith = 'IP';
        try {
            $globals = get_globals();
            if ($globals && isset($globals->fqdnprov) && strtoupper((string) $globals->fqdnprov) === 'YES') {
                $provisionwith = 'FQDN';
            }
        } catch (\Throwable $e) {
            // keep default
        }
        $attrs['provisionwith'] = $provisionwith;

        try {
            $extension = Extension::create($attrs);
        } catch (\Exception $e) {
            Log::warning('Line-test extension create failed', ['error' => $e->getMessage()]);
            return response()->json([
                'Error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 409);
        }

        Extension::where('id', $extension->id)->update(['passwd' => ret_password()]);
        $this->create_default_cos_instances($extension);
        set_commit_dirty();

        $fresh = Extension::find($id);
        return response()->json($this->lineTestPayload($fresh, true), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineTestPayload(Extension $extension, bool $created): array
    {
        $cluster = $extension->cluster ?? null;
        if ($cluster !== null && $cluster !== '') {
            $row = DB::table('cluster')
                ->where('pkey', $cluster)
                ->orWhere('shortuid', $cluster)
                ->orWhere('id', $cluster)
                ->first(['pkey', 'fqdn', 'shortuid']);
            $extension->tenant_pkey = $row ? $row->pkey : $cluster;
            $extension->tenant_fqdn = $row && isset($row->fqdn) ? $row->fqdn : null;
        } else {
            $extension->tenant_pkey = $cluster;
            $extension->tenant_fqdn = null;
        }

        $data = $extension->makeVisible('passwd')->toArray();
        $data['created'] = $created;
        $data['system_line_test'] = true;

        return $data;
    }

/**
 * Generate a new random SIP password for this extension. Old password stops working immediately.
 * Marks config dirty for commit. Returns extension with passwd visible (same shape as show).
 *
 * @param  Extension  $extension  Route binding by shortuid / id / pkey
 * @return \Illuminate\Http\JsonResponse
 */
    public function regenerateSipPassword(Extension $extension)
    {
        $this->assertModelClusterAllowed($extension);
        $id = $extension->id;
        if ($id === null || $id === '') {
            return response()->json(['Error' => 'Extension id is missing'], 409);
        }
        $newPass = ret_password();
        $patch = ['passwd' => $newPass];
        // Password regen must re-send secrets: keep Always; else restore Once (§4.3).
        if (Schema::hasColumn('ipphone', 'sndcreds')) {
            $cur = trim((string) ($extension->sndcreds ?? ''));
            $patch['sndcreds'] = strcasecmp($cur, 'Always') === 0 ? 'Always' : 'Once';
        }
        Extension::where('id', $id)->update($patch);
        set_commit_dirty();

        $extension = Extension::find($id);
        if (!$extension) {
            return response()->json(['Error' => 'Extension not found after update'], 409);
        }

        $cluster = $extension->cluster ?? null;
        if ($cluster !== null && $cluster !== '') {
            $row = DB::table('cluster')->where('pkey', $cluster)->orWhere('shortuid', $cluster)->orWhere('id', $cluster)->first(['pkey']);
            $extension->tenant_pkey = $row ? $row->pkey : $cluster;
        } else {
            $extension->tenant_pkey = $cluster;
        }

        $this->enrichProvisionFields($extension);
        return response()->json($extension->makeVisible('passwd'), 200);
    }

/**
 * Reset provision credential state to Once (SPA "Reset provision state").
 * Server-side only — no phone crypto. See PROVISIONING_SERVER_REQUIREMENTS.md §4.3.
 */
    public function resetProvisionState(Extension $extension)
    {
        $this->assertModelClusterAllowed($extension);
        $id = $extension->id;
        if ($id === null || $id === '') {
            return response()->json(['Error' => 'Extension id is missing'], 409);
        }
        if (!Schema::hasColumn('ipphone', 'sndcreds')) {
            return response()->json([
                'Error' => 'sndcreds column missing — run apply-sqlite-add-provision-columns.sh on the home',
            ], 409);
        }
        Extension::where('id', $id)->update(['sndcreds' => 'Once']);

        $extension = Extension::find($id);
        if (!$extension) {
            return response()->json(['Error' => 'Extension not found after update'], 409);
        }
        $cluster = $extension->cluster ?? null;
        if ($cluster !== null && $cluster !== '') {
            $row = DB::table('cluster')->where('pkey', $cluster)->orWhere('shortuid', $cluster)->orWhere('id', $cluster)->first(['pkey']);
            $extension->tenant_pkey = $row ? $row->pkey : $cluster;
        } else {
            $extension->tenant_pkey = $cluster;
        }
        $this->enrichProvisionFields($extension);
        return response()->json($extension->makeVisible('passwd'), 200);
    }

    /**
     * Attach provision_url (and ensure sndcreds / timestamps present when columns exist).
     */
    private function enrichProvisionFields(?Extension $extension): ?Extension
    {
        if (!$extension) {
            return $extension;
        }
        $fqdn = '';
        try {
            $globals = get_globals();
            if ($globals && !empty($globals->fqdn)) {
                $fqdn = (string) $globals->fqdn;
            }
        } catch (\Throwable $e) {
            $fqdn = '';
        }
        $extension->setAttribute(
            'provision_url',
            ProvisionUrl::forHome($fqdn, $extension->macaddr ?? null)
        );

        $provision = (string) ($extension->provision ?? '');
        if (trim($provision) !== '') {
            $cluster = cluster_identifier_to_shortuid((string) ($extension->cluster ?? ''))
                ?? (string) ($extension->cluster ?? '');
            $customerNames = [];
            if ($cluster !== '' && Schema::hasTable('provision_stream')) {
                $customerNames = ProvisionStream::where('cluster', $cluster)->pluck('pkey')->all();
            }
            $warnings = ProvisionStreamSupport::extensionIncludeWarnings(
                $provision,
                ProvisionStreamSupport::systemNames(),
                $customerNames
            );
            $extension->setAttribute('provision_warnings', $warnings);
        } else {
            $extension->setAttribute('provision_warnings', []);
        }

        return $extension;
    }

/**
 * Return named extension runtime from the PBX
 * 
 * @param  Extension
 * @return extension object
 */
    public function updateruntime (Request $request, Extension $extension) {

        $this->assertModelClusterAllowed($extension);
        // nullable must be in the same rule list as regex (bare ['nullable'] entries were ignored).
        $validator = Validator::make($request->all(), [
            'cfim' => ['nullable', 'regex:/^\+?\d+$/'],
            'cfbs' => ['nullable', 'regex:/^\+?\d+$/'],
            'ringdelay' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $amiHandle = get_ami_handle();
        // AstDB keys must be extension shortuid: LepDial sets agi_extension to shortuid,
        // and phone star-codes key CFIM/CFBS by PJSIP endpoint id (also shortuid).
        $key = $this->runtimeAstdbKey($extension);
        $legacyPkey = (string) ($extension->pkey ?? '');

        if ($request->exists('cfim')) {
            $this->runtimeAstdbSet($amiHandle, 'cfim', $key, $request->input('cfim'));
        }

        if ($request->exists('cfbs')) {
            $this->runtimeAstdbSet($amiHandle, 'cfbs', $key, $request->input('cfbs'));
        }

        if ($request->exists('ringdelay')) {
            $this->runtimeAstdbSet($amiHandle, 'ringdelay', $key, $request->input('ringdelay'));
        }

        if ($legacyPkey !== '' && $legacyPkey !== $key) {
            $this->runtimeAstdbClearLegacy($amiHandle, $legacyPkey);
        }

        $amiHandle->logout();

        return Response::json(null, 204);
    }

/**
 * Delete  Extension instance
 * @param  Extension
 * @return NULL
 */
    public function delete(Extension $extension) {

        $this->assertModelClusterAllowed($extension);
        // Delete related rows only if tables exist; missing tables must not block extension delete
        $aliases = cluster_identifier_aliases($extension->cluster);
        if ($aliases === []) {
            $aliases = [(string) $extension->cluster];
        }
        try {
            IpPhoneCosOpen::where('ipphone_pkey', $extension->pkey)
                ->whereIn('cluster', $aliases)
                ->delete();
        } catch (\Throwable $e) {
            // table may not exist
        }
        try {
            IpPhoneCosClosed::where('ipphone_pkey', $extension->pkey)
                ->whereIn('cluster', $aliases)
                ->delete();
        } catch (\Throwable $e) {
            // table may not exist
        }

        $shortuid = (string) $extension->getAttribute('shortuid');
        $extension->delete();
        pbx3_delete_extension_asterisk_instances($shortuid);

        set_commit_dirty();

        return response()->json(null, 204);
    }
 

	private function create_default_cos_instances($extension) {
		$aliases = cluster_identifier_aliases($extension->cluster);
		if ($aliases === []) {
			$aliases = [(string) $extension->cluster];
		}
		$cluster = cluster_identifier_to_shortuid($extension->cluster) ?? (string) $extension->cluster;

		// Profile HoR: new extensions get tenant default profile when none supplied.
		$existing = trim((string) ($extension->cos_profile ?? ''));
		if ($existing === '') {
			$defaultPkey = CosProfile::defaultPkeyForCluster($cluster);
			if ($defaultPkey !== null && $defaultPkey !== '') {
				Extension::where('id', $extension->id)->update(['cos_profile' => $defaultPkey]);
				$extension->cos_profile = $defaultPkey;
			}
		}

		// Keep junction seed for dual-read / SPA matrix until Slice D cutover (Q8).
		$costable = ClassOfService::whereIn('cluster', $aliases)->get();

		foreach ($costable as $cos) {

			if ($cos->defaultopen == 'YES') {
				IpPhoneCosOpen::create([
    				'ipphone_pkey' => $extension->pkey,
    				'cos_pkey' => $cos->pkey,
    				'cluster' => $cluster,
    				]);
			}

			if ($cos->defaultclosed == 'YES') {
				IpPhoneCosClosed::create([
    				'ipphone_pkey' => $extension->pkey,
    				'cos_pkey' => $cos->pkey,
    				'cluster' => $cluster,
    				]);
			}		
		}
	}

	/** AstDB key for runtime CFIM/CFBS/ringdelay — must match pbx3cagi (shortuid / PJSIP endpoint). */
	private function runtimeAstdbKey(Extension $extension): string
	{
		$key = trim((string) ($extension->shortuid ?? ''));
		if ($key !== '') {
			return $key;
		}
		return trim((string) ($extension->pkey ?? ''));
	}

	/** Read family/key; if empty and legacy pkey differs, try legacy key (pre-shortuid API writes). */
	private function runtimeAstdbGet($amiHandle, string $family, string $key, string $legacyPkey): ?string
	{
		$val = $amiHandle->GetDB($family, $key);
		if (($val === null || $val === '') && $legacyPkey !== '' && $legacyPkey !== $key) {
			$val = $amiHandle->GetDB($family, $legacyPkey);
		}
		return $val;
	}

	/** Write or clear one AstDB family under shortuid key. */
	private function runtimeAstdbSet($amiHandle, string $family, string $key, $value): void
	{
		$empty = $value === null || $value === '';
		if ($empty) {
			$amiHandle->DelDB($family, $key);
		} else {
			$amiHandle->PutDB($family, $key, $value);
		}
	}

	/** Remove pre-shortuid pkey keys once per save (not per field). */
	private function runtimeAstdbClearLegacy($amiHandle, string $legacyPkey): void
	{
		foreach (['cfim', 'cfbs', 'ringdelay'] as $family) {
			$amiHandle->DelDB($family, $legacyPkey);
		}
	}

}
