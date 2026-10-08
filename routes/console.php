<?php

use App\Models\Trunk;
use App\Services\Backup\BackupRunService;
use App\Services\Backup\LocalBackupRetention;
use App\Services\Directory\FleetPreflightService;
use App\Services\Directory\InstanceBackupDirectoryUpload;
use App\Services\Snapshot\SnapshotRetention;
use App\Services\Tenant\TenantMobilityService;
use App\Services\TenantDefaultBackfillService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('pbx3:ops-register-loops', function (\App\Services\Ops\RegisterLoopScanner $scanner) {
    $result = $scanner->run();
    $this->info(sprintf(
        'scanned=%d matched=%d emitted=%d skipped_not_wl=%d errors=%d',
        $result['scanned'],
        $result['matched'],
        $result['emitted'],
        $result['skipped_not_whitelisted'],
        count($result['errors'])
    ));
    foreach ($result['errors'] as $err) {
        $this->warn($err);
    }

    return count($result['errors']) > 0 && $result['emitted'] === 0 ? 1 : 0;
})->purpose('Scan Asterisk auth failures on Fail2ban whitelist; notify Gatekeeper');

Artisan::command('pbx3:ops-egress-qualify', function (\App\Services\Ops\EgressQualifyNotifyScanner $scanner) {
    $result = $scanner->run();
    $this->info(sprintf(
        'checked=%s qualify=%s transition=%s emitted=%s seeded=%s errors=%d',
        $result['checked'] ? 'yes' : 'no',
        $result['qualify'],
        $result['transition'] ?? '-',
        $result['emitted'] ? 'yes' : 'no',
        $result['seeded'] ? 'yes' : 'no',
        count($result['errors'])
    ));
    foreach ($result['errors'] as $err) {
        $this->warn($err);
    }

    return count($result['errors']) > 0 && $result['emitted'] === 0 ? 1 : 0;
})->purpose('Poll Egress PJSIP qualify; notify Gatekeeper on Unavail/cleared');

Artisan::command('pbx3:ops-velocity', function (\App\Services\Ops\VelocityOrchestrator $orchestrator) {
    $result = $orchestrator->run();
    $off = $result['detectors']['off_hours'] ?? null;
    $this->info(sprintf(
        'scanned=%s candidates=%d over=%d emitted=%d cleared=%d acted=%d skipped_hyst=%d policy=%s off_hours=%s errors=%d',
        $result['scanned'] ? 'yes' : 'no',
        $result['candidates'],
        $result['over_threshold'],
        $result['emitted'],
        $result['cleared'],
        $result['acted'],
        $result['skipped_hysteresis'],
        $result['policy_source'] ?? 'env',
        $off === null ? 'off' : 'on',
        count($result['errors'])
    ));
    foreach ($result['errors'] as $err) {
        $this->warn($err);
    }

    return count($result['errors']) > 0 && $result['emitted'] === 0 && $result['cleared'] === 0 ? 1 : 0;
})->purpose('Scan CDR for velocity detectors (IRSF + optional off-hours); notify Gatekeeper');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('tenant:backfill-defaults', function (TenantDefaultBackfillService $backfill) {
    $this->info('Backfilling NULL columns to new schema defaults...');
    $summary = $backfill->run();
    $total = 0;
    foreach ($summary as $table => $columns) {
        foreach ($columns as $column => $count) {
            if ($count > 0) {
                $this->line("  {$table}.{$column}: {$count} row(s) updated");
                $total += $count;
            }
        }
    }
    $this->info($total > 0 ? "Done. {$total} row(s) updated." : 'Done. No NULLs to backfill (or tables missing).');
})->purpose('Set NULL columns to new defaults in existing tenant rows (one-time after schema migration)');

Artisan::command('trunks:set-default-cluster', function () {
    $updated = Trunk::query()->update(['cluster' => 'default']);
    $this->info("Done. {$updated} trunk(s) set to cluster = 'default'.");
})->purpose('Set all existing trunks to the default cluster (TRUNK_ROUTE_MULTITENANCY first cut)');

Artisan::command('pbx3:backup-run {--trigger=scheduled : manual|scheduled|pre-upgrade}', function (BackupRunService $runner) {
    $trigger = $this->option('trigger');
    if (! in_array($trigger, ['manual', 'scheduled', 'pre-upgrade'], true)) {
        $this->error('Invalid --trigger');

        return 1;
    }

    try {
        $result = $runner->run($trigger);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->info('Created '.$result['backup_name']);
    if ($result['s3_uploaded']) {
        $this->info('Uploaded to org bucket.');
    } elseif (app(InstanceBackupDirectoryUpload::class)->isConfigured()) {
        $this->warn('S3 upload failed (local backup kept) — see storage/logs/laravel.log');
    }
    if ($result['pruned'] !== []) {
        $this->line('Pruned local: '.implode(', ', $result['pruned']));
    }

    return 0;
})->purpose('Create local backup, upload to org bucket if configured, FIFO prune to local_max_count');

Artisan::command('pbx3:prune-backups', function (LocalBackupRetention $retention) {
    $removed = $retention->pruneExcess();
    if ($removed === []) {
        $this->info('Nothing to prune.');

        return 0;
    }
    $this->info('Removed: '.implode(', ', $removed));

    return 0;
})->purpose('FIFO prune /opt/pbx3/bkup to PBX3_BACKUP_LOCAL_MAX_COUNT (does not touch S3)');

Artisan::command('pbx3:prune-snapshots', function (SnapshotRetention $retention) {
    $removed = $retention->pruneExcess();
    if ($removed === []) {
        $this->info('Nothing to prune.');

        return 0;
    }
    $this->info('Removed: '.implode(', ', $removed));

    return 0;
})->purpose('FIFO prune /opt/pbx3/snap to PBX3_SNAPSHOT_MAX_COUNT');

Artisan::command('pbx3:upload-backup {filename : e.g. pbx3bak.1716123456.zip} {--trigger=manual : manual|scheduled|pre-upgrade}', function (InstanceBackupDirectoryUpload $upload) {
    $filename = basename($this->argument('filename'));
    $trigger = $this->option('trigger');
    if (! in_array($trigger, ['manual', 'scheduled', 'pre-upgrade'], true)) {
        $this->error('Invalid --trigger');

        return 1;
    }
    if (! $upload->isConfigured()) {
        $this->error('Set PBX3_ORG_BUCKET (and AWS credentials or instance role).');

        return 1;
    }
    $ok = $upload->upload($filename, $trigger);
    if ($ok) {
        $bucket = config('pbx3_directory.org_bucket');
        $this->info("Uploaded {$filename} to s3://{$bucket}/instances/…/backups/… (see laravel.log for key)");

        return 0;
    }
    $this->error('Upload failed — see storage/logs/laravel.log');

    return 1;
})->purpose('Upload a local /opt/pbx3/bkup zip to instances/{ksuid}/backups/ on PBX3_ORG_BUCKET');

Artisan::command('tenant:ingest-built {db : Path to external one-tenant .db} {--dry-run : Plan only; no writes}', function (\App\Services\Tenant\BuiltTenantIngestService $ingest) {
    try {
        $result = $ingest->ingest($this->argument('db'), [
            'dry_run' => (bool) $this->option('dry-run'),
        ]);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $src = $result['source'] ?? [];
    $planned = $result['planned'] ?? [];
    $mode = ! empty($result['dry_run']) ? 'DRY-RUN' : 'APPLIED';
    $this->info("{$mode} built-tenant ingest: ".($planned['pkey'] ?? $src['pkey'] ?? '?'));
    $this->line('  source shortuid: '.($src['shortuid'] ?? '').'  id: '.($src['id'] ?? ''));
    $this->line('  final  shortuid: '.($planned['shortuid'] ?? '').'  id: '.($planned['id'] ?? ''));
    $this->line('  fqdn: '.($planned['fqdn'] ?? ''));
    if (! empty($planned['remint_shortuid'])) {
        $this->warn('  reminted shortuid: '.($src['shortuid'] ?? '').' → '.($planned['shortuid'] ?? ''));
    }
    if (! empty($planned['remint_id'])) {
        $this->warn('  reminted cluster.id: '.($src['id'] ?? '').' → '.($planned['id'] ?? ''));
    }
    foreach ($result['warnings'] ?? [] as $w) {
        $this->warn('  '.$w);
    }
    foreach ($result['blocking_errors'] ?? [] as $err) {
        $this->error('  '.$err);
    }
    $counts = $result['imported_rows'] ?? $result['row_counts'] ?? [];
    foreach ($counts as $table => $count) {
        if ((int) $count > 0) {
            $prefix = isset($result['imported_rows']) ? '+' : '';
            $this->line("  {$table}: {$prefix}{$count}");
        }
    }
    if (isset($result['route_fleet_normalized'])) {
        $this->line('  route Egress normalize: '.$result['route_fleet_normalized'].' row(s)');
    }
    if (! empty($result['enroll_hint'])) {
        $this->newLine();
        $this->comment($result['enroll_hint']);
    }

    return empty($result['blocking_errors']) ? 0 : 1;
})->purpose('Ingest an external one-tenant .db into this home (FLEET_BUILT_TENANT_INGEST)');

Artisan::command('tenant:export {tenant : cluster id, shortuid, or pkey} {--include-recordings : Bundle on-node recording files} {--detach-users : Remove/strip portable users from this instance after packing (move)} {--output= : Override output zip path}', function (TenantMobilityService $mobility) {
    try {
        $result = $mobility->export($this->argument('tenant'), [
            'include_recordings' => (bool) $this->option('include-recordings'),
            'detach_portable_users' => (bool) $this->option('detach-users'),
            'output_path' => $this->option('output') ?: null,
        ]);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $manifest = $result['manifest'];
    $tenant = $manifest['tenant'] ?? [];
    $this->info('Exported tenant '.$tenant['pkey'].' ('.$tenant['shortuid'].')');
    $this->line('Zip: '.$result['zip_path']);
    foreach ($manifest['row_counts'] ?? [] as $table => $count) {
        if ($count > 0) {
            $this->line("  {$table}: {$count}");
        }
    }
    $media = $manifest['media'] ?? [];
    if (! empty($media['greetings_bytes']) || ! empty($media['moh_bytes']) || ! empty($media['recordings_bytes'])) {
        $this->line(sprintf(
            '  media: greetings=%dB moh=%dB recordings=%dB',
            (int) ($media['greetings_bytes'] ?? 0),
            (int) ($media['moh_bytes'] ?? 0),
            (int) ($media['recordings_bytes'] ?? 0)
        ));
    }
    $detach = $result['portable_users_detach'] ?? null;
    if (is_array($detach)) {
        $this->line('Portable users detached: deleted='.$detach['deleted'].' stripped='.$detach['stripped']);
    }

    return 0;
})->purpose('Export one tenant to pbx3tenant.{shortuid}.{epoch}.zip (S8.6)');

Artisan::command('tenant:import {zip : Path to export zip} {--replace : Overwrite existing tenant with same id/shortuid/pkey} {--skip-media : Import DB only}', function (TenantMobilityService $mobility) {
    try {
        $result = $mobility->import($this->argument('zip'), [
            'replace' => (bool) $this->option('replace'),
            'skip_media' => (bool) $this->option('skip-media'),
        ]);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $tenant = $result['tenant'] ?? [];
    $this->info('Imported tenant '.$tenant['pkey'].' ('.$tenant['shortuid'].')');
    foreach ($result['imported_rows'] ?? [] as $table => $count) {
        if ($count > 0) {
            $this->line("  {$table}: +{$count}");
        }
    }
    $pu = $result['portable_users'] ?? [];
    if (! empty($pu['count'])) {
        $this->line('Portable users: created='.($pu['created'] ?? 0).' updated='.($pu['updated'] ?? 0));
    }
    foreach ($pu['skipped'] ?? [] as $skip) {
        $this->warn('  skipped user: '.$skip);
    }
    $media = $result['media'] ?? [];
    if (! empty($media['greetings'])) {
        $this->line('Greeting media installed.');
    }
    if (! empty($media['recordings'])) {
        $this->line('Recording media installed.');
    }
    $this->line('Shorewall FQDN inline rules refreshed (when globals.fqdninspect is YES).');
    $this->comment('Next: SPA Commit, Certificates Sync, DNS cutover, move-tenant.sh — see TENANT_MIGRATION_RUNBOOK.md');

    return 0;
})->purpose('Import tenant from export zip; preserves cluster.id KSUID (S8.6)');

Artisan::command('pbx3:tenant-orphan-audit {--json : Machine-readable report} {--sample=5 : Sample orphan rows per table}', function (\App\Services\Tenant\TenantWipeIntegrityService $integrity) {
    $sample = max(1, min(50, (int) $this->option('sample')));
    $report = $integrity->auditOrphanClusterRows($sample);
    if ($this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['ok'] ? 0 : 1;
    }
    if ($report['ok']) {
        $this->info('Tenant orphan audit: clean (no cluster-scoped orphans).');

        return 0;
    }
    $this->error('Tenant orphan audit: '.$report['total'].' orphan row(s)');
    foreach ($report['by_table'] as $table => $count) {
        $this->line("  {$table}: {$count}");
    }
    $this->comment('Fix: delete or re-home rows whose cluster is not cluster.id|shortuid|pkey. See TENANT_DELETE_DATA_INTEGRITY.md T4.');

    return 1;
})->purpose('T4 — audit child rows whose cluster is not a live tenant identifier');

Artisan::command('pbx3:tenant-wipe-list-check {--schema= : Override path to sqlite_create_tenant.sql} {--json : Machine-readable report}', function (\App\Services\Tenant\TenantWipeIntegrityService $integrity) {
    $schema = $this->option('schema') ?: null;
    if ($schema === null || $schema === '') {
        $packaged = base_path('../pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql');
        if (is_file($packaged)) {
            $schema = $packaged;
        } elseif (is_file((string) config('pbx3_directory.tenant_schema_sql'))) {
            $schema = (string) config('pbx3_directory.tenant_schema_sql');
        }
    }
    try {
        $report = $integrity->compareWipeListToSchema(is_string($schema) ? $schema : null);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }
    if ($this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['ok'] ? 0 : 1;
    }
    if ($report['ok']) {
        $this->info('Wipe list covers '.count($report['schema_tables']).' cluster-scoped tenant schema table(s).');
        if ($report['extra_in_wipe_list'] !== []) {
            $this->comment('Also in TENANT_DATA_TABLES (not in schema parse): '.implode(', ', $report['extra_in_wipe_list']));
        }

        return 0;
    }
    $this->error('TENANT_DATA_TABLES missing: '.implode(', ', $report['missing_from_wipe_list']));

    return 1;
})->purpose('T5 — assert TENANT_DATA_TABLES covers tenant-schema tables with a cluster column');

Artisan::command('pbx3:fleet-preflight', function (FleetPreflightService $preflight) {
    $checks = $preflight->run();
    $failed = 0;
    foreach ($checks as $check) {
        $mark = $check['ok'] ? 'OK' : 'FAIL';
        $this->line(sprintf('[%s] %s — %s', $mark, $check['name'], $check['detail']));
        if (! $check['ok']) {
            $failed++;
        }
    }
    if ($failed > 0) {
        $this->error("Fleet preflight: {$failed} check(s) failed.");

        return 1;
    }
    $this->info('Fleet preflight: all checks passed.');

    return 0;
})->purpose('Verify fleet readiness: KSUID, PBX3_ORG_BUCKET, instance-role S3 access (S8.4)');

Artisan::command('pbx3:recordings-offload', function (\App\Services\Recordings\RecordingOffloadService $offload) {
    $stats = $offload->run();
    $this->info(sprintf(
        'Offload complete: %d moved, %d skipped (not stable), %d errors',
        $stats['offloaded'],
        $stats['skipped'],
        $stats['errors']
    ));

    return $stats['errors'] > 0 ? 1 : 0;
})->purpose('Move stable spool recordings to local archive and index rows (R1.5)');

Artisan::command('pbx3:recordings-s3-upload {--limit= : Max rows this run}', function (
    \App\Services\Recordings\RecordingS3UploadService $upload,
) {
    if (! $upload->isConfigured()) {
        $this->warn('S3 upload not configured (set PBX3_RECORDING_UPLOAD_ENABLED + PBX3_GATEKEEPER_URL + PBX3_GATEKEEPER_TOKEN).');

        return 0;
    }

    $limitOpt = $this->option('limit');
    $limit = is_numeric($limitOpt) ? (int) $limitOpt : null;
    $stats = $upload->run($limit);
    $this->info(sprintf(
        'S3 upload: %d uploaded, %d skipped, %d errors',
        $stats['uploaded'],
        $stats['skipped'],
        $stats['errors']
    ));

    return $stats['errors'] > 0 ? 1 : 0;
})->purpose('Upload local archive recordings to dedicated S3 bucket via gatekeeper presign (S7)');

Artisan::command('pbx3:recordings-retain {--usage-only : Only update cluster.recused}', function (
    \App\Services\Recordings\RecordingRetentionService $retention,
    \App\Services\Recordings\RecordingUsageService $usage,
) {
    if ((bool) $this->option('usage-only')) {
        $count = $usage->updateAllTenants();
        $this->info("Updated recused for {$count} tenant(s).");

        return 0;
    }

    $stats = $retention->run();
    $this->info(sprintf(
        'Retention: %d aged, %d purged, %d size-evicted, %d errors',
        $stats['aged'],
        $stats['purged'],
        $stats['size_evicted'],
        $stats['errors']
    ));

    return $stats['errors'] > 0 ? 1 : 0;
})->purpose('Recording retention (age-out, grace purge, recmaxsize) and recused tally (R1.5)');

Artisan::command('pbx3:recordings-migrate-schema', function (\App\Services\Recordings\RecordingSchemaService $schema) {
    if ($schema->ensureTable()) {
        $this->info('recordings table is present.');

        return 0;
    }
    $this->error('Failed to create recordings table — check PBX3_RECORDINGS_SCHEMA_SQL and laravel.log');

    return 1;
})->purpose('Apply sqlite_add_recordings_table.sql to the instance DB (R1.5)');

Artisan::command('pbx3:recordings-reconcile {--tenant= : Limit to one tenant shortuid} {--no-drift : Only backfill from archive (skip S3/local drift)}', function (
    \App\Services\Recordings\RecordingReconcileService $reconcile,
) {
    $tenant = $this->option('tenant');
    $drift = ! (bool) $this->option('no-drift');
    $stats = $reconcile->run(
        is_string($tenant) && $tenant !== '' ? $tenant : null,
        $drift,
    );
    $this->info(sprintf(
        'Reconcile: %d inserted, %d already indexed, %d repaired, %d removed, %d errors',
        $stats['inserted'],
        $stats['skipped'],
        $stats['repaired'],
        $stats['removed'],
        $stats['errors']
    ));

    return ($stats['errors'] ?? 0) > 0 ? 1 : 0;
})->purpose('Backfill recordings index from archive and repair SQLite ↔ disk ↔ S3 drift (S7.10)');

Artisan::command('pbx3:logs-s3-upload {--limit= : Max files this run}', function (
    \App\Services\Directory\InstanceLogDirectoryUpload $upload,
) {
    if (! $upload->isConfigured()) {
        $this->warn('Log S3 upload not configured (set PBX3_ORG_BUCKET; PBX3_LOG_UPLOAD_ENABLED defaults true).');

        return 0;
    }

    $limitOpt = $this->option('limit');
    $limit = is_numeric($limitOpt) ? (int) $limitOpt : null;
    $stats = $upload->run($limit);
    $this->info(sprintf(
        'Log ship: %d uploaded, %d skipped, %d errors',
        $stats['uploaded'],
        $stats['skipped'],
        $stats['errors']
    ));
    foreach ($stats['details'] as $line) {
        $this->line('  '.$line);
    }

    return $stats['errors'] > 0 ? 1 : 0;
})->purpose('Upload rotated syslog / Asterisk messages / CDR CSV to org bucket logs/ (Phase 1)');

Artisan::command('pbx3:cdr-fixture
    {--deck=irsf : irsf|failed-scan|internal-noise|mixed}
    {--count=12 : Rows to insert}
    {--src=1001 : Extension / src}
    {--accountcode=labtenant : CDR accountcode}
    {--path= : Override SQLite path (preferred for lab)}
    {--force : Allow when APP_ENV=production or set PBX3_CDR_FIXTURE=1}
    {--allow-live : Explicitly permit writing /var/log/asterisk/master.db}
    {--probe : Run VelocityCdrQuery after seed and print counts}', function (
    \App\Services\Cdr\CdrFixtureService $fixture,
    \App\Services\Cdr\VelocityCdrQuery $query,
) {
    $pathOpt = $this->option('path');
    try {
        $result = $fixture->seed([
            'path' => is_string($pathOpt) && $pathOpt !== '' ? $pathOpt : null,
            'deck' => (string) $this->option('deck'),
            'count' => (int) $this->option('count'),
            'src' => (string) $this->option('src'),
            'accountcode' => (string) $this->option('accountcode'),
            'force' => (bool) $this->option('force'),
            'allow_live' => (bool) $this->option('allow-live'),
        ]);
    } catch (\Throwable $e) {
        $this->error('CDR fixture failed: '.$e->getMessage());

        return 1;
    }

    // Point config at the path we wrote so --probe sees the same DB.
    config(['pbx3_cdr.sqlite_path' => $result['path']]);

    $this->info(sprintf(
        'CDR fixture: deck=%s inserted=%d created=%s path=%s',
        $result['deck'],
        $result['inserted'],
        $result['created'] ? 'yes' : 'no',
        $result['path']
    ));

    if ($this->option('probe')) {
        $probe = $query->candidates();
        $this->info(sprintf(
            'Velocity probe: available=%s total=%d window=%dm prefixes=%s from=%s',
            $probe['available'] ? 'yes' : 'no',
            $probe['total'],
            $probe['window_minutes'],
            implode(',', $probe['prefixes']),
            $probe['from']
        ));
        foreach ($probe['by_src'] as $src => $n) {
            $this->line("  src={$src} count={$n}");
        }
    }

    return 0;
})->purpose('Seed lab CDR rows into a path-safe master.db copy (velocity V1)');

Artisan::command('pbx3:cdr-velocity-pack', function (
    \App\Services\Cdr\VelocityCdrPack $pack,
) {
    $result = $pack->run();
    foreach ($result['cases'] as $case) {
        $mark = $case['ok'] ? 'PASS' : 'FAIL';
        $this->line(sprintf('[%s] %s — %s', $mark, $case['id'], $case['detail']));
    }
    $this->info(sprintf(
        'CDR velocity pack: passed=%d failed=%d',
        $result['passed'],
        $result['failed']
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Run fixture CDR decks against VelocityCdrQuery (IRSF pack)');

Artisan::command('pbx3:cos-highrisk-seed
    {--tenant= : Tenant shortuid or pkey}
    {--all : Seed every tenant}
    {--locale= : uk|us (default config PBX3_COS_HIGHRISK_LOCALE)}
    {--force : Refresh dialplan on existing HR_* rules}
    {--oride : Force-include on all extensions (orideopen/closed YES)}', function (
    \App\Services\Tenant\SeedCosHighRiskOnTenantCreate $seeder,
) {
    $all = (bool) $this->option('all');
    $tenantOpt = $this->option('tenant');
    if (! $all && (! is_string($tenantOpt) || trim($tenantOpt) === '')) {
        $this->error('Pass --tenant=SHORTUID or --all');

        return 1;
    }

    $locale = $this->option('locale');
    $locale = is_string($locale) && trim($locale) !== '' ? trim($locale) : null;
    $force = (bool) $this->option('force');
    $oride = (bool) $this->option('oride');

    $query = \App\Models\Tenant::query();
    if (! $all) {
        $key = trim((string) $tenantOpt);
        $query->where(function ($q) use ($key) {
            $q->where('shortuid', $key)->orWhere('pkey', $key);
        });
    }
    $tenants = $query->get();
    if ($tenants->isEmpty()) {
        $this->warn('No matching tenants');

        return 1;
    }

    $total = 0;
    foreach ($tenants as $tenant) {
        $rows = $seeder->seed($tenant, $locale, $force, $oride);
        $label = $tenant->shortuid ?? $tenant->pkey;
        if ($rows === []) {
            $this->line("tenant={$label} — no changes (rules exist; use --force / --oride)");
        } else {
            foreach ($rows as $row) {
                $this->info(sprintf(
                    'tenant=%s pkey=%s oride=%s/%s patterns≈%d',
                    $label,
                    $row->pkey,
                    $row->orideopen,
                    $row->orideclosed,
                    count(preg_split('/\s+/', trim((string) $row->dialplan)) ?: [])
                ));
                $total++;
            }
        }
    }
    $this->info("CoS high-risk seed done: rules_written={$total}");

    return 0;
})->purpose('Seed high-risk CoS deny rules (HR_UK070 / HR_OFFSHORE)');

Artisan::command('pbx3:cdr-import-csv
    {--file= : Asterisk Master.csv (or .gz / accountcode.csv)}
    {--path= : Lab SQLite path (preferred; refuses live master.db)}
    {--truncate : DELETE FROM cdr before import}
    {--limit= : Max rows to insert}
    {--force : Allow when APP_ENV=production or set PBX3_CDR_FIXTURE=1}
    {--allow-live : Explicitly permit writing /var/log/asterisk/master.db}
    {--probe : Run VelocityCdrQuery after import}', function (
    \App\Services\Cdr\CdrCsvImportService $importer,
    \App\Services\Cdr\VelocityCdrQuery $query,
) {
    $file = $this->option('file');
    if (! is_string($file) || trim($file) === '') {
        $this->error('Required: --file=/path/to/Master.csv');

        return 1;
    }
    $pathOpt = $this->option('path');
    $limitOpt = $this->option('limit');
    try {
        $result = $importer->import([
            'file' => trim($file),
            'path' => is_string($pathOpt) && $pathOpt !== '' ? $pathOpt : null,
            'truncate' => (bool) $this->option('truncate'),
            'limit' => is_numeric($limitOpt) ? (int) $limitOpt : null,
            'force' => (bool) $this->option('force'),
            'allow_live' => (bool) $this->option('allow-live'),
        ]);
    } catch (\Throwable $e) {
        $this->error('CDR CSV import failed: '.$e->getMessage());

        return 1;
    }

    config(['pbx3_cdr.sqlite_path' => $result['path']]);
    $this->info(sprintf(
        'CDR CSV import: read=%d inserted=%d skipped=%d truncated=%s created=%s csv=%s path=%s',
        $result['read'],
        $result['inserted'],
        $result['skipped'],
        $result['truncated'] ? 'yes' : 'no',
        $result['created'] ? 'yes' : 'no',
        $result['csv'],
        $result['path']
    ));

    if ($this->option('probe')) {
        $probe = $query->candidates();
        $this->info(sprintf(
            'Velocity probe: available=%s total=%d window=%dm prefixes=%s',
            $probe['available'] ? 'yes' : 'no',
            $probe['total'],
            $probe['window_minutes'],
            implode(',', $probe['prefixes'])
        ));
        foreach ($probe['by_src'] as $src => $n) {
            $this->line("  src={$src} count={$n}");
        }
    }

    return 0;
})->purpose('Import Asterisk Master.csv into a path-safe lab master.db');

Artisan::command('pbx3:cdr-velocity-query
    {--window= : Override T minutes}
    {--prefixes= : Comma-separated high-cost prefixes}', function (
    \App\Services\Cdr\VelocityCdrQuery $query,
) {
    $windowOpt = $this->option('window');
    $window = is_numeric($windowOpt) ? (int) $windowOpt : null;
    $prefixesOpt = $this->option('prefixes');
    $prefixes = null;
    if (is_string($prefixesOpt) && trim($prefixesOpt) !== '') {
        $prefixes = preg_split('/\s*,\s*/', trim($prefixesOpt)) ?: [];
    }

    $result = $query->candidates($window, $prefixes);
    if (! $result['available']) {
        $this->warn('CDR SQLite not available (or empty prefix list) at '.$result['path']);

        return 0;
    }

    $this->info(sprintf(
        'Velocity candidates: total=%d window=%dm from=%s path=%s',
        $result['total'],
        $result['window_minutes'],
        $result['from'],
        $result['path']
    ));
    foreach ($result['by_src'] as $src => $n) {
        $this->line("  src={$src} count={$n}");
    }

    return 0;
})->purpose('Query recent high-cost outbound CDR candidates (velocity V1)');

Artisan::command('pbx3:cdr-prune {--days= : Override local_days.cdr}', function (
    \App\Services\Cdr\CdrRetentionService $retention,
) {
    $daysOpt = $this->option('days');
    $days = is_numeric($daysOpt) ? (int) $daysOpt : null;
    try {
        $stats = $retention->prune($days);
    } catch (\Throwable $e) {
        $this->error('CDR prune failed: '.$e->getMessage());

        return 1;
    }

    if (! $stats['available']) {
        $this->warn('CDR SQLite not available at '.$stats['path']);

        return 0;
    }

    $this->info(sprintf(
        'CDR prune: deleted %d rows older than %s (local_days=%d)',
        $stats['deleted'],
        $stats['cutoff'],
        $stats['local_days']
    ));

    return 0;
})->purpose('Prune Asterisk master.db CDR rows older than local retention (Phase 6)');
