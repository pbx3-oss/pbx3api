<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProvisionStream extends Model
{
    protected $table = 'provision_stream';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $attributes = [
        'cluster' => 'default',
        'body' => '',
    ];

    protected $fillable = [
        'cluster',
        'pkey',
        'body',
        'notes',
        'updated_at',
    ];

    protected $guarded = ['z_created', 'z_updated', 'z_updater'];

    public function resolveRouteBinding($value, $field = null)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;

        $model = static::where('shortuid', $value)->first();
        if ($model) {
            return $model;
        }

        $model = static::whereRaw('LOWER(shortuid) = ?', [strtolower($value)])->first();
        if ($model) {
            return $model;
        }

        $model = static::where('id', $value)->first();
        if ($model) {
            return $model;
        }

        return static::where('pkey', $value)->first();
    }
}
