<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrunkRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Authorization will be handled by middleware
    }

    public function rules()
    {
        $trunk = $this->route('trunk');
        $pkeySubmitted = $this->input('pkey');

        // On update when pkey is unchanged, skip unique check (tenant-safe)
        $pkeyUnchanged = $trunk instanceof \App\Models\Trunk
            && (string) $pkeySubmitted === (string) $trunk->getAttribute('pkey');

        if ($pkeyUnchanged) {
            $pkeyRule = 'required';
        } else {
            // Uniqueness is per cluster; DB stores cluster shortuid
            $clusterShortuid = cluster_identifier_to_shortuid($this->input('cluster'));
            $pkeyRule = Rule::unique('trunks', 'pkey')->where('cluster', $clusterShortuid ?? $this->input('cluster'));
            if ($trunk instanceof \App\Models\Trunk) {
                $pkeyRule->ignore($trunk->getKey(), 'id');
            }
        }

        return [
            'pkey' => ['required', $pkeyRule],
            'cluster' => 'required|exists:cluster,pkey',
            'active' => 'in:YES,NO',
            'alertinfo' => 'string|nullable',
            'callerid' => 'integer|nullable',
            'callprogress' => 'in:ON,OFF',
            'description' => 'string|max:255',
            'devicerec' => 'in:None,Inbound,Outbound,Both',
            // #8c: DISA dropped; CALLBACK hidden/future — only empty/null/None.
            'disa' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value === null || $value === '' || strcasecmp((string) $value, 'None') === 0) {
                    return;
                }
                $fail('DISA and CALLBACK are not supported on trunks.');
            }],
            'disapass' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                $fail('DISA password is not supported; leave empty.');
            }],
            'host' => 'required|string|max:255',
            'inprefix' => 'integer|nullable',
            'match' => 'integer|nullable',
            'moh' => 'in:ON,OFF',
            'password' => ['nullable', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
            'peername' => 'required|string|max:255',
            'register' => 'string|nullable',
            'swoclip' => 'in:YES,NO',
            'tag' => 'string|nullable',
            'transport' => 'in:udp,tcp,tls,wss',
            'transform' => ['regex:/^(\d+?:\d+?\s*)+$/', 'nullable'],
            'trunkname' => 'required|string|max:255',
            'username' => 'string|nullable',
            'z_updater' => 'string|nullable'
        ];
    }
}
