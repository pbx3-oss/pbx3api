<?php

use App\Models\User;
use App\Support\ProvisionStreamSupport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $idpwgen = dirname(__DIR__, 2) . '/../pbx3/pbx3-1/opt/pbx3/golang/idpwgen';
    if (is_executable($idpwgen)) {
        putenv('IDPWGEN_PATH=' . $idpwgen);
        $_ENV['IDPWGEN_PATH'] = $idpwgen;
    }

    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => ':memory:']);
    config(['database.connections.sqlite.prefix' => '']);
    DB::purge('sqlite');
    DB::reconnect('sqlite');

    Schema::create('cluster', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey')->nullable();
    });

    Schema::create('provision_stream', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('cluster')->default('default');
        $table->text('body')->nullable();
        $table->string('notes')->nullable();
        $table->string('updated_at')->nullable();
        $table->string('z_created')->nullable();
        $table->string('z_updated')->nullable();
        $table->string('z_updater')->nullable();
    });

    Schema::create('ipphone', function (Blueprint $table) {
        $table->string('id')->nullable();
        $table->string('pkey')->nullable();
        $table->string('cluster')->nullable();
        $table->text('provision')->nullable();
    });

    DB::table('cluster')->insert([
        ['id' => 'tenantaksuid00000000000001', 'shortuid' => 'tenanta1', 'pkey' => 'TenantA'],
    ]);

    $streams = sys_get_temp_dir().'/pbx3-prov-streams-'.getmypid();
    @mkdir($streams);
    file_put_contents($streams.'/snom.Extension', "base=system\n");
    putenv('PBX3_PROVISION_STREAMS='.$streams);
    $_ENV['PBX3_PROVISION_STREAMS'] = $streams;
});

afterEach(function () {
    $dir = getenv('PBX3_PROVISION_STREAMS');
    if ($dir && is_dir($dir)) {
        @unlink($dir.'/snom.Extension');
        @rmdir($dir);
    }
    putenv('PBX3_PROVISION_STREAMS');
    unset($_ENV['PBX3_PROVISION_STREAMS']);
});

function pbx3ProvStreamAdmin(): User
{
    $user = new User([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'abilities' => ['admin'],
        'allowed_clusters' => [],
        'portable' => false,
    ]);
    $user->id = 1;
    Sanctum::actingAs($user, ['admin']);

    return $user;
}

test('list mixes system and customer streams', function () {
    pbx3ProvStreamAdmin();
    DB::table('provision_stream')->insert([
        'id' => 'ksuidprovstream00000000001',
        'shortuid' => 'ps1',
        'pkey' => 'site.ReceptionBLF',
        'cluster' => 'tenanta1',
        'body' => "linekey.1=blf\n",
        'notes' => null,
        'updated_at' => '2026-10-02T00:00:00Z',
    ]);

    $res = $this->getJson('/api/provision-streams?cluster=tenanta1');
    $res->assertOk();
    $names = collect($res->json('streams'))->pluck('name')->all();
    expect($names)->toContain('snom.Extension');
    expect($names)->toContain('site.ReceptionBLF');
});

test('create customer rejects system name collision', function () {
    pbx3ProvStreamAdmin();
    $res = $this->postJson('/api/provision-streams', [
        'cluster' => 'tenanta1',
        'pkey' => 'snom.Extension',
        'body' => 'x=1',
    ]);
    $res->assertStatus(409);
});

test('delete blocked by refcount unless force', function () {
    pbx3ProvStreamAdmin();
    DB::table('provision_stream')->insert([
        'id' => 'ksuidprovstream00000000002',
        'shortuid' => 'ps2',
        'pkey' => 'site.ReceptionBLF',
        'cluster' => 'tenanta1',
        'body' => "linekey.1=blf\n",
        'notes' => null,
        'updated_at' => null,
    ]);
    DB::table('ipphone')->insert([
        'id' => 'ext1',
        'pkey' => '1001',
        'cluster' => 'tenanta1',
        'provision' => "#INCLUDE snom.Extension\n#INCLUDE site.ReceptionBLF\n",
    ]);

    expect(ProvisionStreamSupport::refcount('tenanta1', 'site.ReceptionBLF'))->toBe(1);

    $blocked = $this->deleteJson('/api/provision-streams/site.ReceptionBLF?cluster=tenanta1');
    $blocked->assertStatus(409);

    $ok = $this->deleteJson('/api/provision-streams/site.ReceptionBLF?cluster=tenanta1&force=1');
    $ok->assertOk();
});

test('copy from system creates customer fragment', function () {
    pbx3ProvStreamAdmin();
    $res = $this->postJson('/api/provision-streams/copy-from-system', [
        'cluster' => 'tenanta1',
        'from' => 'snom.Extension',
        'to' => 'site.snom.Extension',
    ]);
    $res->assertCreated();
    expect($res->json('source'))->toBe('customer');
    expect($res->json('body'))->toContain('base=system');
});

test('secret line warnings on save', function () {
    pbx3ProvStreamAdmin();
    $res = $this->postJson('/api/provision-streams', [
        'cluster' => 'tenanta1',
        'pkey' => 'site.Secrets',
        'body' => "password=hardcoded\nok=1\n",
    ]);
    $res->assertCreated();
    expect($res->json('warnings'))->not->toBeEmpty();
});
