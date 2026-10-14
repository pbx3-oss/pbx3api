<?php

namespace App\Services\Tenant;

use App\Models\Sysglobal;
use App\Services\Fleet\FleetPostureService;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Export/import a single tenant mini-DB for fleet moves (S8.6).
 * Preserves cluster.id (KSUID) and object KSUIDs. Trunks are instance-owned — not exported.
 */
class TenantMobilityService
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> Tenant-scoped tables (no trunks — instance-owned). */
    public const TENANT_DATA_TABLES = [
        'agent',
        'appl',
        'cos',
        'cos_profile',
        'cos_profile_open',
        'cos_profile_closed',
        'dateseg',
        'greeting',
        'holiday',
        'inroutes',
        'ipphone',
        'ipphonecosopen',
        'ipphonecosclosed',
        'ivrmenu',
        'page',
        'meetme',
        'queue',
        'recordings',
        'route',
        'dialalias',
        'route_profile',
        'route_profile_line',
        'clid_block',
        'provision_stream',
        // Laravel `users` are not in sqlite_create_tenant.sql — packed as portable_users.json (P4).
    ];

    /**
     * @param  array{include_recordings?: bool, output_path?: string|null, detach_portable_users?: bool}  $options
     * @return array{zip_path: string, manifest: array<string, mixed>, portable_users?: array{deleted: int, stripped: int}|null}
     */
    public function export(string $identifier, array $options = []): array
    {
        $cluster = $this->resolveClusterRow($identifier);
        $aliases = cluster_identifier_aliases($cluster->shortuid ?? $cluster->pkey ?? $cluster->id);
        if ($aliases === []) {
            throw new \RuntimeException("Tenant not found: {$identifier}");
        }

        $shortuid = (string) $cluster->shortuid;
        $epoch = time();
        $exportDir = rtrim((string) config('pbx3_directory.tenant_export_dir'), '/');
        if (! is_dir($exportDir) && ! @mkdir($exportDir, 0755, true) && ! is_dir($exportDir)) {
            throw new \RuntimeException("Cannot create export directory: {$exportDir}");
        }

        $workDir = sys_get_temp_dir().'/pbx3tenant-export-'.bin2hex(random_bytes(4));
        if (! mkdir($workDir, 0700, true)) {
            throw new \RuntimeException('Cannot create temp export directory');
        }

        $detachResult = null;
        try {
            $miniDb = $workDir.'/tenant.sqlite.db';
            $rowCounts = $this->buildMiniDatabase($this->openPdo(), $cluster, $aliases, $miniDb);

            $portable = app(PortableUserMobility::class);
            $portableUsers = $portable->collectForTenant($shortuid);
            $portable->writeExportFile($workDir, $shortuid, $portableUsers);
            $rowCounts['portable_users'] = count($portableUsers);

            $mediaRoot = $workDir.'/media';
            $greetingBytes = $this->exportGreetingMedia($shortuid, $mediaRoot.'/greetings/'.$shortuid);
            $mohBytes = $this->exportMohMedia($shortuid, $mediaRoot.'/moh/'.$shortuid);
            $recordingBytes = 0;
            if (! empty($options['include_recordings'])) {
                $recordingBytes = $this->exportRecordingMedia($shortuid, $mediaRoot.'/recordings');
            }

            $globals = Sysglobal::query()->first();
            $manifest = [
                'schema_version' => self::SCHEMA_VERSION,
                'created_at' => gmdate('c', $epoch),
                'source_instance_id' => $globals->id ?? null,
                'source_fqdn' => $globals->fqdn ?? null,
                'tenant' => [
                    'id' => $cluster->id,
                    'shortuid' => $cluster->shortuid,
                    'pkey' => $cluster->pkey,
                    'fqdn' => $cluster->fqdn ?? null,
                ],
                'row_counts' => $rowCounts,
                'media' => [
                    'greetings_bytes' => $greetingBytes,
                    'moh_bytes' => $mohBytes,
                    'recordings_bytes' => $recordingBytes,
                    'include_recordings' => ! empty($options['include_recordings']),
                ],
                'portable_users' => count($portableUsers),
            ];
            file_put_contents($workDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $zipName = "pbx3tenant.{$shortuid}.{$epoch}.zip";
            $zipPath = $options['output_path'] ?? "{$exportDir}/{$zipName}";
            $this->createZip($workDir, $zipPath);

            if (! empty($options['detach_portable_users'])) {
                $detachResult = $portable->detachFromSource($shortuid);
            }

            return [
                'zip_path' => $zipPath,
                'manifest' => $manifest,
                'portable_users_detach' => $detachResult,
            ];
        } finally {
            $this->removeTree($workDir);
        }
    }

    /**
     * @param  array{replace?: bool, skip_media?: bool}  $options
     * @return array<string, mixed>
     */
    public function import(string $zipPath, array $options = []): array
    {
        if (! is_file($zipPath)) {
            throw new \RuntimeException("Export zip not found: {$zipPath}");
        }

        $workDir = sys_get_temp_dir().'/pbx3tenant-import-'.bin2hex(random_bytes(4));
        if (! mkdir($workDir, 0700, true)) {
            throw new \RuntimeException('Cannot create temp import directory');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException("Cannot open zip: {$zipPath}");
            }
            $zip->extractTo($workDir);
            $zip->close();

            $manifestPath = $workDir.'/manifest.json';
            if (! is_file($manifestPath)) {
                throw new \RuntimeException('Export zip missing manifest.json');
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest) || ($manifest['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
                throw new \RuntimeException('Unsupported or missing manifest schema_version');
            }

            $tenant = $manifest['tenant'] ?? [];
            $clusterId = (string) ($tenant['id'] ?? '');
            $shortuid = (string) ($tenant['shortuid'] ?? '');
            if ($clusterId === '' || $shortuid === '') {
                throw new \RuntimeException('Manifest tenant.id and tenant.shortuid are required');
            }

            $miniDb = $workDir.'/tenant.sqlite.db';
            if (! is_file($miniDb)) {
                throw new \RuntimeException('Export zip missing tenant.sqlite.db');
            }

            $pdo = $this->openPdo();
            $conflict = $this->findImportConflict($clusterId, $shortuid, (string) ($tenant['pkey'] ?? ''));
            if ($conflict !== null && empty($options['replace'])) {
                throw new \RuntimeException("Tenant already exists ({$conflict}). Use --replace to overwrite.");
            }

            $pdo->beginTransaction();
            try {
                if ($conflict !== null && ! empty($options['replace'])) {
                    $aliases = cluster_identifier_aliases($shortuid);
                    $this->removeTenantRows($pdo, $aliases, $clusterId);
                    // Drop destination portable users for this tenant before re-import.
                    app(PortableUserMobility::class)->removeOrStripForTenant($shortuid);
                }
                // mergeMiniDatabase opens a separate PDO to the mini-DB — SQLite forbids
                // ATTACH DATABASE while this connection has an open transaction.
                $imported = $this->mergeMiniDatabase($pdo, $miniDb);
                if (app(FleetPostureService::class)->isFleetNode()) {
                    $imported['route_fleet_normalized'] = $this->normalizeFleetRoutes($pdo);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $portableImport = app(PortableUserMobility::class)->importFromWorkDir($workDir, $shortuid);

            $mediaResult = ['greetings' => false, 'moh' => false, 'recordings' => false];
            if (empty($options['skip_media'])) {
                $mediaResult = $this->installMedia($workDir, $shortuid, (string) ($tenant['pkey'] ?? ''));
            }

            if (! app(FleetPostureService::class)->isFleetNode()) {
                pbx3_update_fqdn_inline_optional();
            }

            return [
                'tenant' => $tenant,
                'imported_rows' => $imported,
                'portable_users' => $portableImport,
                'media' => $mediaResult,
                'manifest' => $manifest,
            ];
        } finally {
            $this->removeTree($workDir);
        }
    }

    /**
     * Per-table row counts for aliases that destroyTenantData would wipe (T1 preflight).
     * Informational only — does not block wipe (I1).
     *
     * @param  object{id?: string, shortuid?: string, pkey?: string, fqdn?: string|null}|string  $tenant
     * @return array{
     *   tenant: array{id: string, shortuid: string, pkey: string|null, fqdn: string|null},
     *   aliases: list<string>,
     *   tables: array<string, int>,
     *   total_rows: int,
     *   cluster: int
     * }
     */
    public function countTenantData(object|string $tenant): array
    {
        if (is_string($tenant)) {
            $tenant = $this->resolveClusterRow($tenant);
        }

        $pkey = (string) ($tenant->pkey ?? '');
        if ($pkey === 'default') {
            throw new \InvalidArgumentException('Cannot delete default tenant');
        }

        $clusterId = (string) ($tenant->id ?? '');
        $shortuid = (string) ($tenant->shortuid ?? '');
        if ($clusterId === '' || $shortuid === '') {
            throw new \RuntimeException('Tenant id and shortuid are required to count wipe rows');
        }

        $aliases = cluster_identifier_aliases($shortuid);
        if ($aliases === []) {
            $aliases = [$shortuid, $clusterId];
        }

        $pdo = $this->openPdo();
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $tables = [];
        $total = 0;
        foreach (self::TENANT_DATA_TABLES as $table) {
            if (! $this->tableExists($pdo, $table)) {
                continue;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE cluster IN ({$placeholders})");
            $stmt->execute($aliases);
            $count = (int) $stmt->fetchColumn();
            $tables[$table] = $count;
            $total += $count;
        }

        return [
            'tenant' => [
                'id' => $clusterId,
                'shortuid' => $shortuid,
                'pkey' => $pkey !== '' ? $pkey : null,
                'fqdn' => isset($tenant->fqdn) ? (string) $tenant->fqdn : null,
            ],
            'aliases' => $aliases,
            'tables' => $tables,
            'total_rows' => $total,
            'cluster' => 1,
        ];
    }

    /**
     * Full tenant wipe: all TENANT_DATA_TABLES rows for cluster aliases + cluster row.
     * Matches import --replace cascade. Does not remove greeting/recording/MOH media trees (v1).
     * Portable users are stripped by the caller via PortableUserMobility.
     *
     * @param  object{id: string, shortuid: string, pkey?: string}  $tenant
     */
    public function destroyTenantData(object $tenant): void
    {
        $pkey = (string) ($tenant->pkey ?? '');
        if ($pkey === 'default') {
            throw new \InvalidArgumentException('Cannot delete default tenant');
        }

        $clusterId = (string) ($tenant->id ?? '');
        $shortuid = (string) ($tenant->shortuid ?? '');
        if ($clusterId === '' || $shortuid === '') {
            throw new \RuntimeException('Tenant id and shortuid are required to destroy');
        }

        $aliases = cluster_identifier_aliases($shortuid);
        if ($aliases === []) {
            $aliases = [$shortuid, $clusterId];
        }

        $pdo = $this->openPdo();
        $pdo->beginTransaction();
        try {
            $this->pruneInboundDialAliases($pdo, $aliases, isset($tenant->fqdn) ? (string) $tenant->fqdn : null);
            $this->removeTenantRows($pdo, $aliases, $clusterId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * T2 — same-home sibling dialaliases targeting this tenant (other clusters' rows).
     * Cross-home peers are pruned by Fleet Delete mesh phase (I7).
     *
     * @param  list<string>  $aliases
     */
    private function pruneInboundDialAliases(\PDO $pdo, array $aliases, ?string $fqdn): void
    {
        if (! $this->tableExists($pdo, 'dialalias')) {
            return;
        }

        $aliasPlaceholders = implode(',', array_fill(0, count($aliases), '?'));
        $params = $aliases;
        $targetClauses = ["target_cluster IN ({$aliasPlaceholders})"];

        $fqdnNorm = strtolower(trim((string) $fqdn));
        if ($fqdnNorm !== '') {
            $targetClauses[] = 'LOWER(TRIM(COALESCE(target_fqdn, \'\'))) = ?';
            $params[] = $fqdnNorm;
        }

        $sql = 'DELETE FROM dialalias WHERE ('.implode(' OR ', $targetClauses).')'
            ." AND cluster NOT IN ({$aliasPlaceholders})";
        foreach ($aliases as $a) {
            $params[] = $a;
        }

        $pdo->prepare($sql)->execute($params);
    }

    /**
     * @return object{id: string, shortuid: string, pkey: string, fqdn?: string|null}
     */
    public function resolveClusterRow(string $identifier): object
    {
        $row = DB::table('cluster')
            ->where('pkey', $identifier)
            ->orWhere('shortuid', $identifier)
            ->orWhere('id', $identifier)
            ->first(['id', 'shortuid', 'pkey', 'fqdn']);

        if ($row === null) {
            throw new \RuntimeException("Tenant not found: {$identifier}");
        }

        return $row;
    }

    private function openPdo(): \PDO
    {
        $path = config('database.connections.sqlite.database');
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 30000');

        return $pdo;
    }

    /**
     * @param  list<string>  $aliases
     * @return array<string, int>
     */
    private function buildMiniDatabase(\PDO $source, object $cluster, array $aliases, string $miniDbPath): array
    {
        if (file_exists($miniDbPath)) {
            unlink($miniDbPath);
        }
        touch($miniDbPath);

        $schemaPath = (string) config('pbx3_directory.tenant_schema_sql');
        if (! is_file($schemaPath)) {
            throw new \RuntimeException("Tenant schema SQL not found: {$schemaPath}");
        }
        $schema = file_get_contents($schemaPath);
        if ($schema === false) {
            throw new \RuntimeException("Cannot read tenant schema: {$schemaPath}");
        }

        $mini = new \PDO('sqlite:'.$miniDbPath);
        $mini->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $mini->exec($schema);

        $attachPath = str_replace("'", "''", $miniDbPath);
        $source->exec("ATTACH DATABASE '{$attachPath}' AS tenant_export");

        try {
            $rowCounts = [];
            $clusterId = (string) $cluster->id;
            // Named columns only — live ALTER order can diverge from sqlite_create_tenant.sql
            // (provision cols tipped mid-flight). Positional SELECT * scrambles the mini-DB.
            $rowCounts['cluster'] = $this->insertAttachedBySharedColumns(
                $source,
                'tenant_export',
                'cluster',
                'id = ?',
                [$clusterId]
            );

            $placeholders = implode(',', array_fill(0, count($aliases), '?'));
            foreach (self::TENANT_DATA_TABLES as $table) {
                if (! $this->tableExists($source, $table)) {
                    continue;
                }
                if (! $this->tableExistsOnConnection($source, 'tenant_export', $table)) {
                    continue;
                }
                $rowCounts[$table] = $this->insertAttachedBySharedColumns(
                    $source,
                    'tenant_export',
                    $table,
                    "cluster IN ({$placeholders})",
                    $aliases
                );
            }

            return $rowCounts;
        } finally {
            $source->exec('DETACH DATABASE tenant_export');
        }
    }

    /**
     * Copy rows from mini-DB into the live DB. Uses a second PDO connection — not ATTACH —
     * because import() wraps this in BEGIN … COMMIT and SQLite rejects ATTACH in a transaction.
     *
     * @return array<string, int>
     */
    private function mergeMiniDatabase(\PDO $target, string $miniDbPath): array
    {
        $mini = new \PDO('sqlite:'.$miniDbPath);
        $mini->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $mini->exec('PRAGMA busy_timeout = 30000');

        $imported = [];
        if ($this->tableExists($mini, 'cluster')) {
            $imported['cluster'] = $this->copyTableRows($mini, $target, 'cluster');
        }

        foreach (self::TENANT_DATA_TABLES as $table) {
            if (! $this->tableExists($mini, $table) || ! $this->tableExists($target, $table)) {
                continue;
            }
            $imported[$table] = $this->copyTableRows($mini, $target, $table);
        }

        return $imported;
    }

    /** @return int Rows updated */
    private function normalizeFleetRoutes(\PDO $pdo): int
    {
        $egress = (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress');
        $stmt = $pdo->prepare(
            'UPDATE route SET path1 = ?, path2 = NULL, path3 = NULL, path4 = NULL WHERE path1 IS NOT NULL OR path2 IS NOT NULL OR path3 IS NOT NULL OR path4 IS NOT NULL'
        );
        $stmt->execute([$egress]);

        return $stmt->rowCount();
    }

    private function copyTableRows(\PDO $from, \PDO $to, string $table): int
    {
        $fromColumns = $this->tableColumns($from, $table);
        $toColumns = $this->tableColumns($to, $table);
        $columns = array_values(array_intersect($fromColumns, $toColumns));
        if ($columns === []) {
            return 0;
        }
        $quoted = array_map(static fn (string $c) => '"'.$c.'"', $columns);
        $colList = implode(', ', $quoted);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $to->prepare("INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})");

        $count = 0;
        foreach ($from->query("SELECT {$colList} FROM {$table}") as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
            $insert->execute($values);
            $count++;
        }

        return $count;
    }

    /**
     * INSERT into an ATTACH'd schema using the intersection of column names
     * (never positional SELECT * — live ALTER order ≠ CREATE TABLE order).
     *
     * @param  list<mixed>  $params
     */
    private function insertAttachedBySharedColumns(
        \PDO $pdo,
        string $attachedAlias,
        string $table,
        string $whereSql,
        array $params
    ): int {
        $srcCols = $this->tableColumnsOnConnection($pdo, '', $table);
        $dstCols = $this->tableColumnsOnConnection($pdo, $attachedAlias, $table);
        $columns = array_values(array_intersect($srcCols, $dstCols));
        if ($columns === []) {
            return 0;
        }
        $quoted = array_map(static fn (string $c) => '"'.$c.'"', $columns);
        $colList = implode(', ', $quoted);
        $stmt = $pdo->prepare(
            "INSERT INTO {$attachedAlias}.{$table} ({$colList}) SELECT {$colList} FROM {$table} WHERE {$whereSql}"
        );
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /** @return list<string> */
    private function tableColumns(\PDO $pdo, string $table): array
    {
        return $this->tableColumnsOnConnection($pdo, '', $table);
    }

    /**
     * @param  ''|'tenant_export'|'tenant_import'  $attachedAlias
     * @return list<string>
     */
    private function tableColumnsOnConnection(\PDO $pdo, string $attachedAlias, string $table): array
    {
        // SQLite: PRAGMA schema.table_info(name) — not table_info(schema.name)
        $pragma = $attachedAlias === ''
            ? "PRAGMA table_info({$table})"
            : "PRAGMA {$attachedAlias}.table_info({$table})";
        $stmt = $pdo->query($pragma);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_column($rows, 'name');
    }

    /**
     * @param  list<string>  $aliases
     */
    private function removeTenantRows(\PDO $pdo, array $aliases, string $clusterId): void
    {
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        foreach (self::TENANT_DATA_TABLES as $table) {
            if (! $this->tableExists($pdo, $table)) {
                continue;
            }
            $pdo->prepare("DELETE FROM {$table} WHERE cluster IN ({$placeholders})")->execute($aliases);
        }
        $pdo->prepare('DELETE FROM cluster WHERE id = ?')->execute([$clusterId]);
    }

    private function findImportConflict(string $clusterId, string $shortuid, string $pkey): ?string
    {
        $byId = DB::table('cluster')->where('id', $clusterId)->first(['id']);
        if ($byId !== null) {
            return "id={$clusterId}";
        }
        $byShort = DB::table('cluster')->where('shortuid', $shortuid)->first(['id']);
        if ($byShort !== null) {
            return "shortuid={$shortuid}";
        }
        if ($pkey !== '' && $pkey !== 'default') {
            $byPkey = DB::table('cluster')->where('pkey', $pkey)->first(['id']);
            if ($byPkey !== null) {
                return "pkey={$pkey}";
            }
        }

        return null;
    }

    private function exportGreetingMedia(string $shortuid, string $destDir): int
    {
        $soundsRoot = rtrim((string) config('pbx3_directory.tenant_sounds_root'), '/');
        $src = "{$soundsRoot}/{$shortuid}";
        if (! is_dir($src)) {
            return 0;
        }

        return $this->copyTree($src, $destDir);
    }

    /**
     * Pack custom MOH for moh-{shortuid} only (never instance system moh/).
     */
    private function exportMohMedia(string $shortuid, string $destDir): int
    {
        if ($shortuid === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $shortuid)) {
            return 0;
        }
        $mohRoot = rtrim((string) config('pbx3_directory.tenant_moh_root'), '/');
        $src = "{$mohRoot}/moh-{$shortuid}";
        if (! is_dir($src)) {
            return 0;
        }

        return $this->copyTree($src, $destDir);
    }

    private function exportRecordingMedia(string $shortuid, string $destDir): int
    {
        $recRoot = rtrim((string) config('pbx3_directory.tenant_recordings_root'), '/');
        if (! is_dir($recRoot)) {
            return 0;
        }

        if (! is_dir($destDir) && ! mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
            return 0;
        }

        $tenantDir = "{$recRoot}/{$shortuid}";
        if (! is_dir($tenantDir)) {
            return 0;
        }

        return $this->copyTree($tenantDir, $destDir);
    }

    /**
     * @return array{greetings: bool, moh: bool, recordings: bool}
     */
    private function installMedia(string $workDir, string $shortuid, string $tenantPkey): array
    {
        $result = ['greetings' => false, 'moh' => false, 'recordings' => false];
        $soundsRoot = rtrim((string) config('pbx3_directory.tenant_sounds_root'), '/');
        $greetingsSrc = "{$workDir}/media/greetings/{$shortuid}";
        if (is_dir($greetingsSrc)) {
            $dest = "{$soundsRoot}/{$shortuid}";
            [$ok] = pbx3_request_syscmd('/bin/mkdir -p '.escapeshellarg($dest));
            if ($ok !== null) {
                pbx3_request_syscmd('/bin/cp -a '.escapeshellarg($greetingsSrc).'/. '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chown -R asterisk:asterisk '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chmod -R u+rwX,go+rX '.escapeshellarg($dest));
                $result['greetings'] = true;
            }
        }

        $mohSrc = "{$workDir}/media/moh/{$shortuid}";
        if (is_dir($mohSrc) && preg_match('/^[A-Za-z0-9_-]+$/', $shortuid)) {
            $mohRoot = rtrim((string) config('pbx3_directory.tenant_moh_root'), '/');
            $dest = "{$mohRoot}/moh-{$shortuid}";
            [$ok] = pbx3_request_syscmd('/bin/mkdir -p '.escapeshellarg($dest));
            if ($ok !== null) {
                pbx3_request_syscmd('/bin/cp -a '.escapeshellarg($mohSrc).'/. '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chown -R asterisk:asterisk '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chmod -R u+rwX,go+rX '.escapeshellarg($dest));
                $result['moh'] = true;
                // Same effect as SPA upload: pick up new files without waiting for Commit.
                pbx3_request_syscmd("/usr/sbin/asterisk -rx 'moh reload'");
            }
        }

        $recordingsSrc = "{$workDir}/media/recordings";
        if (is_dir($recordingsSrc)) {
            $recRoot = rtrim((string) config('pbx3_directory.tenant_recordings_root'), '/');
            $dest = "{$recRoot}/{$shortuid}";
            [$ok] = pbx3_request_syscmd('/bin/mkdir -p '.escapeshellarg($dest));
            if ($ok !== null) {
                pbx3_request_syscmd('/bin/cp -a '.escapeshellarg($recordingsSrc).'/. '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chown -R asterisk:asterisk '.escapeshellarg($dest));
                pbx3_request_syscmd('/bin/chmod -R u+rwX,go+rX '.escapeshellarg($dest));
                $result['recordings'] = true;
            }
        }

        return $result;
    }

    private function createZip(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create zip: {$zipPath}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = ltrim(substr($path, strlen($sourceDir)), '/');
            $zip->addFile($path, $relative);
        }
        $zip->close();

        if (! is_file($zipPath)) {
            throw new \RuntimeException("Zip was not created: {$zipPath}");
        }
        @chmod($zipPath, 0664);
    }

    private function copyTree(string $src, string $dest): int
    {
        if (! is_dir($dest) && ! mkdir($dest, 0755, true) && ! is_dir($dest)) {
            return 0;
        }

        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
                $bytes += filesize($target) ?: 0;
            }
        }

        return $bytes;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        return $this->tableExistsOnConnection($pdo, '', $table);
    }

    /** @param  ''|'tenant_export'|'tenant_import'  $attachedAlias */
    private function tableExistsOnConnection(\PDO $pdo, string $attachedAlias, string $table): bool
    {
        $catalog = $attachedAlias === '' ? 'sqlite_master' : "{$attachedAlias}.sqlite_master";
        $stmt = $pdo->prepare("SELECT 1 FROM {$catalog} WHERE type = 'table' AND name = ? LIMIT 1");
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
