<?php

namespace App\Services;

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ClassOfServiceController;
use App\Http\Controllers\CosProfileController;
use App\Http\Controllers\ClidBlockController;
use App\Http\Controllers\ProvisionStreamController;
use App\Http\Controllers\ConferenceController;
use App\Http\Controllers\DayTimerController;
use App\Http\Controllers\CustomAppController;
use App\Http\Controllers\DialAliasController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\GreetingRecordController;
use App\Http\Controllers\HelpCoreController;
use App\Http\Controllers\HolidayTimerController;
use App\Http\Controllers\InboundRouteController;
use App\Http\Controllers\IvrController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\RouteProfileController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TrunkController;
use App\Models\Agent;
use App\Models\ClassOfService;
use App\Models\CosProfile;
use App\Models\ClidBlock;
use App\Models\ProvisionStream;
use App\Models\Conference;
use App\Models\CustomApp;
use App\Models\DayTimer;
use App\Models\DialAlias;
use App\Models\Extension;
use App\Models\Greeting;
use App\Models\HelpCore;
use App\Models\HolidayTimer;
use App\Models\InboundRoute;
use App\Models\Ivr;
use App\Models\Queue;
use App\Models\Route;
use App\Models\RouteProfile;
use App\Models\Tenant;
use App\Models\Trunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Schema metadata for admin panels: read_only, updateable, defaults from DB + controllers.
 * Single source of truth: DB for columns/defaults, controller updateableColumns for editable.
 *
 * @see pbx3spa/workingdocs/FIELD_MUTABILITY_API_PLAN.md
 */
class SchemaService
{
    /**
     * Resource key => [Controller class, Model class].
     * Table name comes from Model::$table when building schema.
     */
    protected static array $resourceMapping = [
        'extensions' => [ExtensionController::class, Extension::class],
        'queues'     => [QueueController::class, Queue::class],
        'conferences' => [ConferenceController::class, Conference::class],
        'cosrules' => [ClassOfServiceController::class, ClassOfService::class],
        'cosprofiles' => [CosProfileController::class, CosProfile::class],
        'daytimers' => [DayTimerController::class, DayTimer::class],
        'holidaytimers' => [HolidayTimerController::class, HolidayTimer::class],
        'routeprofiles' => [RouteProfileController::class, RouteProfile::class],
        'greetingrecords' => [GreetingRecordController::class, Greeting::class],
        'agents'     => [AgentController::class, Agent::class],
        'customapps' => [CustomAppController::class, CustomApp::class],
        'dialaliases' => [DialAliasController::class, DialAlias::class],
        'helpcore'   => [HelpCoreController::class, HelpCore::class],
        'routes'     => [RouteController::class, Route::class],
        'trunks'     => [TrunkController::class, Trunk::class],
        'ivrs'       => [IvrController::class, Ivr::class],
        'inroutes'   => [InboundRouteController::class, InboundRoute::class],
        'clidblocks' => [ClidBlockController::class, ClidBlock::class],
        'provisionstreams' => [ProvisionStreamController::class, ProvisionStream::class],
        'tenants'    => [TenantController::class, Tenant::class],
    ];

    /**
     * Return the resource → [Controller, Model] mapping.
     *
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function getResourceMapping(): array
    {
        return self::$resourceMapping;
    }

    /**
     * Build schema metadata for all resources: read_only, updateable, defaults from DB + controllers.
     * No cache; runs fresh each request.
     *
     * @return array<string, array{read_only: list<string>, updateable: list<string>, defaults: array<string, mixed>}>
     */
    public function getSchemas(): array
    {
        $out = [];
        $app = app();

        foreach (self::$resourceMapping as $resourceKey => [$controllerClass, $modelClass]) {
            try {
                $controller = $app->make($controllerClass);
                $model = $app->make($modelClass);
                $table = $model->getTable();

                $updateable = $controller->getUpdateableColumns();
                $columns = $this->getTableColumnInfo($table);

                if ($columns === []) {
                    $out[$resourceKey] = [
                        'read_only' => [],
                        'updateable' => $updateable,
                        'defaults' => [],
                    ];
                    continue;
                }

                $allColumnNames = array_column($columns, 'name');
                $defaults = [];
                foreach ($columns as $col) {
                    $name = $col['name'];
                    $dflt = $col['dflt_value'] ?? null;
                    $defaults[$name] = $this->normaliseDefaultValue($dflt);
                }

                $readOnly = array_values(array_diff($allColumnNames, $updateable));
                // Only add id/shortuid to read_only when the table has those columns (e.g. Device has neither)
                if (in_array('id', $allColumnNames, true) && ! in_array('id', $readOnly, true)) {
                    $readOnly[] = 'id';
                }
                if (in_array('shortuid', $allColumnNames, true) && ! in_array('shortuid', $readOnly, true)) {
                    $readOnly[] = 'shortuid';
                }
                $readOnly = array_values(array_unique($readOnly));

                $out[$resourceKey] = [
                    'read_only' => $readOnly,
                    'updateable' => $updateable,
                    'defaults' => $defaults,
                ];
            } catch (\Throwable $e) {
                Log::warning('SchemaService: failed to build schema for resource ' . $resourceKey . ': ' . $e->getMessage());
                $out[$resourceKey] = [
                    'read_only' => [],
                    'updateable' => [],
                    'defaults' => [],
                ];
            }
        }

        return $out;
    }

    /**
     * Run PRAGMA table_info for the table; return list of name, type, dflt_value.
     *
     * @return list<array{name: string, type: string, dflt_value: mixed}>
     */
    protected function getTableColumnInfo(string $table): array
    {
        try {
            $rows = DB::select("SELECT name, type, dflt_value FROM pragma_table_info(?)", [$table]);
        } catch (\Throwable $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'name' => $row->name,
                'type' => $row->type ?? '',
                'dflt_value' => $row->dflt_value,
            ];
        }
        return $result;
    }

    /**
     * Normalise SQLite default value for JSON (strip surrounding single quotes, cast int/float).
     */
    protected function normaliseDefaultValue(mixed $dflt): mixed
    {
        if ($dflt === null || $dflt === '') {
            return null;
        }
        if (is_int($dflt) || is_float($dflt)) {
            return $dflt;
        }
        $s = (string) $dflt;
        // SQLite PRAGMA often returns string defaults with quotes, e.g. 'YES' or 'default'
        if (strlen($s) >= 2 && $s[0] === "'" && $s[strlen($s) - 1] === "'") {
            $s = substr($s, 1, -1);
        }
        if ($s === '' || strtoupper($s) === 'NULL') {
            return null;
        }
        if (is_numeric($s)) {
            return str_contains($s, '.') ? (float) $s : (int) $s;
        }
        return $s;
    }
}
