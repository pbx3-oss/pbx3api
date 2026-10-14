<?php


use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AsteriskFileController;
use App\Http\Controllers\AstAmiController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ClidBlockController;
use App\Http\Controllers\ProvisionStreamController;
use App\Http\Controllers\CosCloseController;
use App\Http\Controllers\CosOpenController;
use App\Http\Controllers\ClassOfServiceController;
use App\Http\Controllers\CosProfileController;
use App\Http\Controllers\ConferenceController;
use App\Http\Controllers\CustomAppController;
use App\Http\Controllers\DayTimerController;
use App\Http\Controllers\DialAliasController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\HelpCoreController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\FirewallController;
use App\Http\Controllers\GreetingController;
use App\Http\Controllers\GreetingRecordController;
use App\Http\Controllers\HolidayTimerController;
use App\Http\Controllers\InboundRouteController;
use App\Http\Controllers\IvrController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\CdrController;
use App\Http\Controllers\HomePulseController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\RecordingController;
use App\Http\Controllers\SnapShotController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\RouteProfileController;
use App\Http\Controllers\SchemaController;
use App\Http\Controllers\SysCommandController;
use App\Http\Controllers\SysglobalController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TrunkController;
use App\Http\Controllers\FleetPostureController;
use App\Http\Controllers\FleetMobilityController;


Route::group(['prefix' => 'auth'], function () {
/**
 *  Only login / 2FA verify need no privileges
 */
    Route::post('login', [AuthController::class, 'login']);
    Route::post('2fa/verify', [AuthController::class, 'verifyTwoFactor']);
/**
 * logout, whoami, change-own-password, own 2FA — any authenticated user
 */
    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::get('logout', [AuthController::class, 'logout']);
        Route::get('whoami', [AuthController::class, 'user']);
        Route::put('password', [AuthController::class, 'changePassword']);
        Route::post('2fa/setup', [AuthController::class, 'setupTwoFactor']);
        Route::post('2fa/confirm', [AuthController::class, 'confirmTwoFactor']);
        Route::post('2fa/disable', [AuthController::class, 'disableTwoFactor']);
        Route::post('2fa/recovery', [AuthController::class, 'regenerateRecoveryCodes']);
    });

    Route::group(['middleware' => ['auth:sanctum', 'ability:admin,tenant']], function () {
/**
 * Twin (follow-me / celltwin) AstDB writes. Family is hardcoded to srktwin server-side
 * (never client-supplied) and the key must resolve to an extension in the caller's cluster scope
 * — see AstAmiController::assertTwinKeyAllowed().
 */
        Route::put('astamis/DBput/srktwin/{key}/{value}', [AstAmiController::class, 'dbputTwin']);
        Route::delete('astamis/DBdel/srktwin/{key}', [AstAmiController::class, 'dbdelTwin']);
    });

/**
 *  Only admins can create, delete and view users
 */
    Route::middleware(['auth:sanctum', 'abilities:admin'])->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::get('users', [AuthController::class, 'index']);
        Route::get('users/mail/{email}', [AuthController::class, 'userByEmail']);
        Route::get('users/name/{name}', [AuthController::class, 'userByName']);
        Route::get('users/endpoint/{endpoint}', [AuthController::class, 'userByEndpoint']);
        Route::put('users/{id}', [AuthController::class, 'update']);
        Route::put('users/{id}/password', [AuthController::class, 'forcePassword']);
        Route::delete('users/{id}/2fa', [AuthController::class, 'clearUserTwoFactor']);
        Route::delete('users/revoke/{id}', [AuthController::class, 'revoke']);
        Route::get('users/{id}', [AuthController::class, 'userById']);
        Route::delete('users/{id}', [AuthController::class, 'delete']);
    });
});

Route::middleware(['auth:sanctum'])->get('fleet-posture', [FleetPostureController::class, 'show']);

/**
 * Fleet control-plane mobility API (S8.10). Bearer PBX3_FLEET_SERVICE_TOKEN — not Sanctum.
 */
Route::middleware(['fleet.token'])->prefix('fleet')->group(function () {
    Route::get('preflight', [FleetMobilityController::class, 'preflight']);
    Route::get('egress-qualify', [FleetMobilityController::class, 'egressQualify']);
    Route::post('tenants', [FleetMobilityController::class, 'storeTenant']);
    Route::post('tenants/{tenant}/export', [FleetMobilityController::class, 'export']);
    Route::post('tenants/import', [FleetMobilityController::class, 'import']);
    Route::post('commit', [FleetMobilityController::class, 'commit']);
    Route::post('certificates/sync', [FleetMobilityController::class, 'certificatesSync']);
    Route::get('tenants/{tenant}/wipe-preflight', [FleetMobilityController::class, 'wipePreflight']);
    Route::delete('tenants/{tenant}', [FleetMobilityController::class, 'destroyTenant']);
    Route::put('sitename', [FleetMobilityController::class, 'putSitename']);
    // C2 — managed dialalias projections (Site Groups); commit separately via POST /fleet/commit
    Route::get('dialaliases', [FleetMobilityController::class, 'listDialAliases']);
    Route::put('dialaliases', [FleetMobilityController::class, 'upsertDialAlias']);
    Route::delete('dialaliases', [FleetMobilityController::class, 'deleteDialAlias']);
});

Route::middleware(['auth:sanctum', 'abilities:admin'])->group(function () {
    Route::get('test/admin-only', function () {
        return response()->json(['message' => 'Admin access granted']);
    });
});

// Instance user privileges: admin | tenant | recordings — see INSTANCE_USER_PRIVILEGES_REQUIREMENTS.md

/**
 * Tenant ops (ability:admin,tenant — CheckForAnyAbility). Cluster row-scope in controllers.
 */
Route::middleware(['auth:sanctum', 'ability:admin,tenant'])->group(function () {
    Route::get('schemas', [SchemaController::class, 'index']);

    Route::get('agents', [AgentController::class, 'index']);
    Route::get('agents/export/pdf', [AgentController::class, 'exportPdf']);
    Route::get('agents/{agent}', [AgentController::class, 'show']);
    Route::post('agents', [AgentController::class, 'save']);
    Route::put('agents/{agent}', [AgentController::class, 'update']);
    Route::delete('agents/{agent}', [AgentController::class, 'delete']);

    Route::get('extensions', [ExtensionController::class, 'index']);
    Route::get('extensions/live', [ExtensionController::class, 'indexLive']);
    Route::get('extensions/export/pdf', [ExtensionController::class, 'exportPdf']);
    Route::post('extensions/line-test/ensure', [ExtensionController::class, 'ensureLineTest']);
    Route::get('extensions/{extension}', [ExtensionController::class, 'show']);
    Route::get('extensions/{extension}/runtime', [ExtensionController::class, 'showruntime']);
    Route::get('extensions/{extension}/cos', [ExtensionController::class, 'showcos']);
    Route::post('extensions', [ExtensionController::class, 'save']);
    Route::put('extensions/{extension}', [ExtensionController::class, 'update']);
    Route::put('extensions/{extension}/runtime', [ExtensionController::class, 'updateruntime']);
    Route::put('extensions/{extension}/cos', [ExtensionController::class, 'updatecos']);
    Route::post('extensions/{extension}/regenerate-sip-password', [ExtensionController::class, 'regenerateSipPassword']);
    Route::post('extensions/{extension}/reset-provision-state', [ExtensionController::class, 'resetProvisionState']);
    Route::delete('extensions/{extension}', [ExtensionController::class, 'delete']);

    Route::get('conferences', [ConferenceController::class, 'index']);
    Route::get('conferences/export/pdf', [ConferenceController::class, 'exportPdf']);
    Route::get('conferences/{conference}', [ConferenceController::class, 'show']);
    Route::post('conferences', [ConferenceController::class, 'save']);
    Route::put('conferences/{conference}', [ConferenceController::class, 'update']);
    Route::delete('conferences/{conference}', [ConferenceController::class, 'delete']);

    Route::get('queues', [QueueController::class, 'index']);
    Route::get('queues/export/pdf', [QueueController::class, 'exportPdf']);
    Route::get('queues/{queue}', [QueueController::class, 'show']);
    Route::post('queues', [QueueController::class, 'save']);
    Route::put('queues/{queue}', [QueueController::class, 'update']);
    Route::delete('queues/{queue}', [QueueController::class, 'delete']);

    Route::get('ivrs', [IvrController::class, 'index']);
    Route::get('ivrs/export/pdf', [IvrController::class, 'exportPdf']);
    Route::get('ivrs/{ivr}', [IvrController::class, 'show']);
    Route::post('ivrs', [IvrController::class, 'save']);
    Route::put('ivrs/{ivr}', [IvrController::class, 'update']);
    Route::delete('ivrs/{ivr}', [IvrController::class, 'delete']);

    Route::get('inboundroutes', [InboundRouteController::class, 'index']);
    Route::get('inboundroutes/export/pdf', [InboundRouteController::class, 'exportPdf']);
    Route::get('inboundroutes/{inboundroute}', [InboundRouteController::class, 'show']);
    Route::post('inboundroutes', [InboundRouteController::class, 'save']);
    Route::put('inboundroutes/{inboundroute}', [InboundRouteController::class, 'update']);
    Route::delete('inboundroutes/{inboundroute}', [InboundRouteController::class, 'delete']);

    Route::get('clidblocks', [ClidBlockController::class, 'index']);
    Route::get('clidblocks/{clidblock}', [ClidBlockController::class, 'show']);
    Route::post('clidblocks', [ClidBlockController::class, 'save']);
    Route::put('clidblocks/{clidblock}', [ClidBlockController::class, 'update']);
    Route::delete('clidblocks/{clidblock}', [ClidBlockController::class, 'delete']);

    Route::get('provision-streams', [ProvisionStreamController::class, 'index']);
    Route::post('provision-streams/copy-from-system', [ProvisionStreamController::class, 'copyFromSystem']);
    Route::get('provision-streams/{name}', [ProvisionStreamController::class, 'show'])->where('name', '.*');
    Route::post('provision-streams', [ProvisionStreamController::class, 'save']);
    Route::put('provision-streams/{name}', [ProvisionStreamController::class, 'update'])->where('name', '.*');
    Route::delete('provision-streams/{name}', [ProvisionStreamController::class, 'delete'])->where('name', '.*');

    Route::get('daytimers', [DayTimerController::class, 'index']);
    Route::get('daytimers/export/pdf', [DayTimerController::class, 'exportPdf']);
    Route::get('daytimers/{daytimer}', [DayTimerController::class, 'show']);
    Route::post('daytimers', [DayTimerController::class, 'save']);
    Route::put('daytimers/{daytimer}', [DayTimerController::class, 'update']);
    Route::delete('daytimers/{daytimer}', [DayTimerController::class, 'delete']);

    Route::get('holidaytimers', [HolidayTimerController::class, 'index']);
    Route::get('holidaytimers/export/pdf', [HolidayTimerController::class, 'exportPdf']);
    Route::get('holidaytimers/{holidaytimer}', [HolidayTimerController::class, 'show']);
    Route::post('holidaytimers', [HolidayTimerController::class, 'save']);
    Route::put('holidaytimers/{holidaytimer}', [HolidayTimerController::class, 'update']);
    Route::delete('holidaytimers/{holidaytimer}', [HolidayTimerController::class, 'delete']);

    Route::get('routeprofiles', [RouteProfileController::class, 'index']);
    Route::get('routeprofiles/export/pdf', [RouteProfileController::class, 'exportPdf']);
    Route::get('routeprofiles/{routeprofile}', [RouteProfileController::class, 'show']);
    Route::post('routeprofiles', [RouteProfileController::class, 'save']);
    Route::put('routeprofiles/{routeprofile}', [RouteProfileController::class, 'update']);
    Route::delete('routeprofiles/{routeprofile}', [RouteProfileController::class, 'delete']);

    Route::get('coscloses', [CosCloseController::class, 'index']);
    Route::get('coscloses/{cosclose}', [CosCloseController::class, 'show']);
    Route::post('coscloses', [CosCloseController::class, 'save']);
    Route::put('coscloses/{cosclose}', [CosCloseController::class, 'update']);
    Route::delete('coscloses/{cosclose}', [CosCloseController::class, 'delete']);

    Route::get('cosopens', [CosOpenController::class, 'index']);
    Route::get('cosopens/{cosopen}', [CosOpenController::class, 'show']);
    Route::post('cosopens', [CosOpenController::class, 'save']);
    Route::put('cosopens/{cosopen}', [CosOpenController::class, 'update']);
    Route::delete('cosopens/{cosopen}', [CosOpenController::class, 'delete']);

    Route::get('cosrules', [ClassOfServiceController::class, 'index']);
    Route::get('cosrules/export/pdf', [ClassOfServiceController::class, 'exportPdf']);
    Route::get('cosrules/{classofservice}', [ClassOfServiceController::class, 'show']);
    Route::post('cosrules', [ClassOfServiceController::class, 'save']);
    Route::put('cosrules/{classofservice}', [ClassOfServiceController::class, 'update']);
    Route::delete('cosrules/{classofservice}', [ClassOfServiceController::class, 'delete']);

    Route::get('cosprofiles', [CosProfileController::class, 'index']);
    Route::get('cosprofiles/{cosprofile}', [CosProfileController::class, 'show']);
    Route::post('cosprofiles', [CosProfileController::class, 'save']);
    Route::put('cosprofiles/{cosprofile}', [CosProfileController::class, 'update']);
    Route::delete('cosprofiles/{cosprofile}', [CosProfileController::class, 'delete']);

    Route::get('greetings', [GreetingController::class, 'index']);
    Route::get('greetings/{greeting}', [GreetingController::class, 'download']);
    Route::post('greetings', [GreetingController::class, 'save']);
    Route::delete('greetings/{greeting}', [GreetingController::class, 'delete']);

    Route::get('greetingrecords', [GreetingRecordController::class, 'index']);
    Route::get('greetingrecords/export/pdf', [GreetingRecordController::class, 'exportPdf']);
    Route::get('greetingrecords/{greetingrecord}', [GreetingRecordController::class, 'show']);
    Route::get('greetingrecords/{greetingrecord}/download', [GreetingRecordController::class, 'download']);
    Route::post('greetingrecords', [GreetingRecordController::class, 'save']);
    Route::put('greetingrecords/{greetingrecord}', [GreetingRecordController::class, 'update']);
    Route::post('greetingrecords/{greetingrecord}/replace', [GreetingRecordController::class, 'replace']);
    Route::delete('greetingrecords/{greetingrecord}', [GreetingRecordController::class, 'delete']);

    Route::get('destinations', [DestinationController::class, 'index']);

    Route::get('cdr', [CdrController::class, 'index']);
    Route::get('home/pulse', [HomePulseController::class, 'show']);

    Route::get('helpcore', [HelpCoreController::class, 'index']);
    Route::get('helpcore/{helpcore}', [HelpCoreController::class, 'show']);

    Route::get('syscommands/commitstatus', [SysCommandController::class, 'commitstatus']);
    Route::get('syscommands/commit', [SysCommandController::class, 'commit']);
    Route::get('syscommands/pbxrunstate', [SysCommandController::class, 'pbxrunstate']);
    Route::get('syscommands/sysnotes', [SysCommandController::class, 'sysnotes']);

    // Home chip / read-only instance label (PUT stays admin-only below)
    Route::get('sysglobals', [SysglobalController::class, 'index']);
});

/**
 * Recordings listen/download (ability:admin,recordings).
 */
Route::middleware(['auth:sanctum', 'ability:admin,recordings'])->group(function () {
    Route::get('recordings', [RecordingController::class, 'index']);
    Route::get('recordings/{recording}/stream', [RecordingController::class, 'stream']);
    Route::get('recordings/{recording}/download', [RecordingController::class, 'download']);
});

/**
 * Admin-only: trunks, routes, tenants, system, AMI, user-adjacent help write, etc.
 */
Route::middleware(['auth:sanctum', 'abilities:admin'])->group(function () {
/**
 *  Asterisk AMI
 */
    Route::get('astamis', [AstAmiController::class, 'index']);
    Route::get('astamis/CoreSettings', [AstAmiController::class, 'coresettings']);
    Route::get('astamis/CoreStatus', [AstAmiController::class, 'corestatus']);

    Route::get('astamis/ExtensionState/{id}{context?}', [AstAmiController::class, 'extensionstate']);
    Route::get('astamis/MailboxCount/{id}', [AstAmiController::class, 'mailboxcount']);
    Route::get('astamis/MailboxStatus/{id}', [AstAmiController::class, 'mailboxstatus']);
    Route::get('astamis/QueueStatus/{id}', [AstAmiController::class, 'queuestatus']);
    Route::get('astamis/QueueSummary/{id}', [AstAmiController::class, 'queuesummary']);
    Route::get('astamis/Reload', [AstAmiController::class, 'reload']);
    Route::post('astamis/originate', [AstAmiController::class, 'originate']);
    Route::get('astamis/DBget/{id}/{key}', [AstAmiController::class, 'dbget']);
    Route::put('astamis/DBput/{id}/{key}/{value}', [AstAmiController::class, 'dbput']);
    Route::delete('astamis/DBdel/{id}/{key}', [AstAmiController::class, 'dbdel']);
    Route::delete('astamis/Hangup/{id}/{key}', [AstAmiController::class, 'hangup']);
    Route::get('astamis/{action}/{id?}', [AstAmiController::class, 'getlist']);

/**
 * Backups
 */
    Route::get('backups', [BackupController::class, 'index']);
    Route::get('backups/new', [BackupController::class, 'new']);
    Route::get('backups/archive/{backup_stamp}/download-url', [BackupController::class, 'downloadArchiveUrl'])
        ->where('backup_stamp', '\d{8}T\d{6}Z');
    Route::post('backups/restore-from-archive', [BackupController::class, 'restoreFromArchive']);
    Route::get('backups/{backup}', [BackupController::class, 'download']);
    Route::post('backups', [BackupController::class, 'save']);
    Route::put('backups/{backup}', [BackupController::class, 'update']);
    Route::delete('backups/{backup}', [BackupController::class, 'delete']);

/**
 * Custom Apps
 */
    Route::get('customapps', [CustomAppController::class, 'index']);
    Route::get('customapps/{customapp}', [CustomAppController::class, 'show']);
    Route::post('customapps', [CustomAppController::class, 'save']);
    Route::put('customapps/{customapp}', [CustomAppController::class, 'update']);
    Route::delete('customapps/{customapp}', [CustomAppController::class, 'delete']);

/**
 * Help messages write (GET is under tenant group)
 */
    Route::post('helpcore', [HelpCoreController::class, 'save']);
    Route::put('helpcore/{helpcore}', [HelpCoreController::class, 'update']);
    Route::delete('helpcore/{helpcore}', [HelpCoreController::class, 'delete']);

/**
 * Firewall
 */
    Route::get('firewalls', [FirewallController::class, 'index']);
    Route::post('firewalls', [FirewallController::class, 'save']);
    Route::put('firewalls', [FirewallController::class, 'apply']);
    Route::get('firewalls/ipv4', [FirewallController::class, 'ipv4']);
    Route::get('firewalls/ipv6', [FirewallController::class, 'ipv6']);
    Route::post('firewalls/ipv4', [FirewallController::class, 'ipv4save']);
    Route::post('firewalls/ipv6', [FirewallController::class, 'ipv6save']);
    Route::put('firewalls/ipv4', [FirewallController::class, 'ipv4restart']);
    Route::put('firewalls/ipv6', [FirewallController::class, 'ipv6restart']);

/**
 * Asterisk config files (/etc/asterisk)
 */
    Route::get('astfiles', [AsteriskFileController::class, 'index']);
    Route::get('astfiles/{filename}', [AsteriskFileController::class, 'show']);
    Route::put('astfiles/{filename}', [AsteriskFileController::class, 'update']);

/**
 * Logs
 */
    Route::get('logs', [LogController::class, 'index']);
    Route::get('logs/retention', [LogController::class, 'retentionShow']);
    Route::put('logs/retention', [LogController::class, 'retentionUpdate']);
    Route::get('logs/archive', [LogController::class, 'archiveIndex']);
    Route::get('logs/archive/download-url', [LogController::class, 'archiveDownloadUrl']);
    Route::get('logs/cdrs{limit}', [LogController::class, 'showcdr']);
    Route::get('logs/{logfile}/download', [LogController::class, 'download'])->where('logfile', '.*');
    Route::get('logs/{logfile}', [LogController::class, 'show'])->where('logfile', '.*');

/**
 * Snapshots
 */
    Route::get('snapshots', [SnapShotController::class, 'index']);
    Route::get('snapshots/new', [SnapShotController::class, 'new']);
    Route::get('snapshots/{snapshot}', [SnapShotController::class, 'download']);
    Route::post('snapshots', [SnapShotController::class, 'save']);
    Route::put('snapshots/{snapshot}', [SnapShotController::class, 'update']);
    Route::delete('snapshots/{snapshot}', [SnapShotController::class, 'delete']);

/**
 * Routes (outbound)
 */
    Route::get('routes', [RouteController::class, 'index']);
    Route::get('routes/export/pdf', [RouteController::class, 'exportPdf']);
    Route::get('routes/{route}', [RouteController::class, 'show']);
    Route::post('routes', [RouteController::class, 'save']);
    Route::put('routes/{route}', [RouteController::class, 'update']);
    Route::delete('routes/{route}', [RouteController::class, 'delete']);

/**
 * Dial prefixes (tenant short dial) — instance admin only (no tenant admin / no CRM customer grouping).
 */
    Route::get('dialaliases', [DialAliasController::class, 'index']);
    Route::get('dialaliases/{dialalias}', [DialAliasController::class, 'show']);
    Route::post('dialaliases', [DialAliasController::class, 'save']);
    Route::put('dialaliases/{dialalias}', [DialAliasController::class, 'update']);
    Route::delete('dialaliases/{dialalias}', [DialAliasController::class, 'delete']);

/**
 * Certificates
 */
    Route::get('certificates/active', [CertificateController::class, 'active']);
    Route::get('certificates/letsencrypt', [CertificateController::class, 'letsencrypt']);
    Route::post('certificates/letsencrypt/setup', [CertificateController::class, 'setup']);
    Route::post('certificates/letsencrypt/sync', [CertificateController::class, 'sync']);
    Route::post('certificates/letsencrypt/renew', [CertificateController::class, 'renew']);
    Route::get('certificates/custom', [CertificateController::class, 'customIndex']);
    Route::post('certificates/custom', [CertificateController::class, 'customStore']);
    Route::delete('certificates/custom', [CertificateController::class, 'customDestroy']);

/**
 * System Commands (except commit / commitstatus / pbxrunstate — tenant group)
 */
    Route::get('syscommands', [SysCommandController::class, 'index']);
    Route::get('syscommands/reboot', [SysCommandController::class, 'reboot']);
    Route::get('syscommands/start', [SysCommandController::class, 'start']);
    Route::get('syscommands/stop', [SysCommandController::class, 'stop']);
    Route::put('syscommands/hostname', [SysCommandController::class, 'sethostname']);
    Route::put('syscommands/dns', [SysCommandController::class, 'setdns']);
    Route::put('syscommands/smtp', [SysCommandController::class, 'setsmtp']);
    Route::get('syscommands/timezones', [SysCommandController::class, 'timezones']);
    Route::put('syscommands/timezone', [SysCommandController::class, 'settimezone']);
    Route::put('syscommands/icmp', [SysCommandController::class, 'seticmp']);
    Route::get('syscommands/sipdebug/status', [SysCommandController::class, 'sipdebugStatus']);
    Route::post('syscommands/sipdebug/arm', [SysCommandController::class, 'sipdebugArm']);
    Route::post('syscommands/sipdebug/disarm', [SysCommandController::class, 'sipdebugDisarm']);

/**
 * System Globals write
 */
    Route::put('sysglobals', [SysglobalController::class, 'update']);

/**
 * Tenants
 */
    Route::get('tenants', [TenantController::class, 'index']);
    Route::get('tenants/export/pdf', [TenantController::class, 'exportPdf']);
    Route::get('tenants/{tenant}/wipe-preflight', [TenantController::class, 'wipePreflight']);
    Route::get('tenants/{tenant}/moh', [TenantController::class, 'listMoh']);
    Route::post('tenants/{tenant}/moh', [TenantController::class, 'uploadMoh']);
    Route::get('tenants/{tenant}/moh/{filename}', [TenantController::class, 'downloadMoh'])
        ->where('filename', '[^/]+');
    Route::delete('tenants/{tenant}/moh/{filename}', [TenantController::class, 'deleteMoh'])
        ->where('filename', '[^/]+');
    Route::get('tenants/{tenant}', [TenantController::class, 'show']);
    Route::post('tenants', [TenantController::class, 'save']);
    Route::put('tenants/{tenant}', [TenantController::class, 'update']);
    Route::delete('tenants/{tenant}', [TenantController::class, 'delete']);

/**
 * Trunks
 */
    Route::get('trunks', [TrunkController::class, 'index']);
    Route::get('trunks/live', [TrunkController::class, 'indexLive']);
    Route::get('trunks/export/pdf', [TrunkController::class, 'exportPdf']);
    Route::get('trunks/{trunk}', [TrunkController::class, 'show']);
    Route::post('trunks', [TrunkController::class, 'save']);
    Route::put('trunks/{trunk}', [TrunkController::class, 'update']);
    Route::delete('trunks/{trunk}', [TrunkController::class, 'delete']);
});

Route::fallback(function () {
    return response()->json([
        'message' => 'Unauthorised/Page Not Found'], 404);
});
