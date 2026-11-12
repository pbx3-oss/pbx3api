<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trunk extends Model
{
    //
    /** API runs against tenant schema (sqlite_create_tenant.sql). Trunks table follows tenant pattern (id, pkey, ...). */
    protected $table = 'trunks';
    // Use id (KSUID, globally unique) so save() updates only one row when pkey is reused across tenants
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    // Defaults for trunks table; only columns that exist in schema 
    protected $attributes = [
    'active' 		=> 'YES',
	'callprogress'  => 'NO',
	'closeroute' 	=> 'Operator',
	'cluster' 		=> 'default',
	'moh' 			=> 'NO',
	'openroute' 	=> 'Operator',
	'swoclip' 		=> 'YES'
    ];

    /** Mass-assignable (whitelist). Schema: sqlite_create_instance.sql. Excludes id, shortuid (set on create), z_* (system-only). */
    protected $fillable = [
        'pkey',
        'active',
        'alertinfo',
        'callback',
        'callerid',
        'callprogress',
        'closeroute',
        'cluster',
        'cname',
        'description',
        'devicerec',
        'disa',
        'disapass',
        'host',
        'iaxreg',
        'inprefix',
        'match',
        'moh',
        'openroute',
        'password',
        'peername',
        'pjsipreg',
        'privileged',
        'register',
        'swoclip',
        'tag',
        'technology',
        'transform',
        'inbound_transform',
        'transport',
        'trunkname',
        'username',
        'pjsip_overlay',
    ];

    /** Attributes excluded from array/JSON: SIP/DISA secrets must never round-trip in list/detail responses. */
    protected $hidden = ['password', 'disapass'];

	/**
	 * Resolve route model binding by shortuid (globally unique) instead of pkey (tenant-scoped).
	 * Falls back to pkey for backward compatibility if shortuid not found.
	 */
	public function resolveRouteBinding($value, $field = null)
	{
		// Try shortuid first (globally unique)
		$model = static::where('shortuid', $value)->first();
		if ($model) {
			return $model;
		}
		
		// Fallback to pkey for backward compatibility (though pkey is tenant-scoped and may be ambiguous)
		return static::where('pkey', $value)->first();
	}
}
