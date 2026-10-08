<?php

uses(Tests\TestCase::class);

use App\Services\Tenant\TenantMobilityService;

/** @var string|null */
$mohRoot = null;
/** @var string|null */
$workRoot = null;

beforeEach(function () use (&$mohRoot, &$workRoot) {
    $mohRoot = sys_get_temp_dir().'/pbx3moh-'.bin2hex(random_bytes(4));
    $workRoot = sys_get_temp_dir().'/pbx3moh-work-'.bin2hex(random_bytes(4));
    mkdir($mohRoot, 0700, true);
    mkdir($workRoot, 0700, true);
    config(['pbx3_directory.tenant_moh_root' => $mohRoot]);
});

afterEach(function () use (&$mohRoot, &$workRoot) {
    foreach ([$mohRoot, $workRoot] as $dir) {
        if (! is_string($dir) || ! is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
    $mohRoot = null;
    $workRoot = null;
});

test('exportMohMedia packs custom moh-{shortuid} files and ignores system moh', function () use (&$mohRoot, &$workRoot) {
    $shortuid = 'hf3zzv';
    $custom = "{$mohRoot}/moh-{$shortuid}";
    mkdir($custom, 0700, true);
    file_put_contents("{$custom}/hold.wav", 'custom-moh');
    mkdir("{$mohRoot}/moh", 0700, true);
    file_put_contents("{$mohRoot}/moh/default.wav", 'system-moh');

    $svc = new TenantMobilityService;
    $export = new ReflectionMethod($svc, 'exportMohMedia');
    $export->setAccessible(true);
    $bytes = $export->invoke($svc, $shortuid, "{$workRoot}/media/moh/{$shortuid}");

    expect($bytes)->toBe(strlen('custom-moh'))
        ->and(is_file("{$workRoot}/media/moh/{$shortuid}/hold.wav"))->toBeTrue()
        ->and(file_get_contents("{$workRoot}/media/moh/{$shortuid}/hold.wav"))->toBe('custom-moh')
        ->and(is_file("{$workRoot}/media/moh/{$shortuid}/default.wav"))->toBeFalse();
});

test('exportMohMedia returns zero when custom directory missing', function () use (&$workRoot) {
    $svc = new TenantMobilityService;
    $export = new ReflectionMethod($svc, 'exportMohMedia');
    $export->setAccessible(true);
    $bytes = $export->invoke($svc, 'nosuch1', "{$workRoot}/media/moh/nosuch1");

    expect($bytes)->toBe(0);
});

test('exportMohMedia rejects unsafe shortuid', function () use (&$mohRoot, &$workRoot) {
    $svc = new TenantMobilityService;
    $export = new ReflectionMethod($svc, 'exportMohMedia');
    $export->setAccessible(true);
    $bytes = $export->invoke($svc, '../etc', "{$workRoot}/media/moh/bad");

    expect($bytes)->toBe(0)
        ->and(is_dir("{$mohRoot}/moh-../etc"))->toBeFalse();
});
