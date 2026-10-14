<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PBX3 org directory bucket (Phase 4+)
    |--------------------------------------------------------------------------
    |
    | When PBX3_ORG_BUCKET is set, instance backups may be copied to
    | s3://{bucket}/instances/{globals.id}/backups/{stamp}/ after local create.
    | Telephony does not depend on this; upload is best-effort and async.
    |
    */

    'org_bucket' => env('PBX3_ORG_BUCKET'),

    'backup_upload_enabled' => env('PBX3_DIRECTORY_BACKUP_UPLOAD', true),

    // Tag uploads class=backup for S3 lifecycle (requires s3:PutObjectTagging on node IAM).
    'backup_s3_tagging' => filter_var(env('PBX3_BACKUP_S3_TAGGING', true), FILTER_VALIDATE_BOOL),

    // Agreed retention (DESIGN_RULES.md option C): local max 9 (FIFO) + S3 maxage_days (lifecycle ops).
    'local_max_count' => (int) env('PBX3_BACKUP_LOCAL_MAX_COUNT', 9),

    // Snapshot FIFO under /opt/pbx3/snap/ (S9.7) — after commit + manual snapshots/new.
    'snapshot_max_count' => (int) env('PBX3_SNAPSHOT_MAX_COUNT', 9),

    'default_backup_policy' => [
        'maxage_days' => (int) env('PBX3_BACKUP_MAXAGE_DAYS', 30),
        'glacier_after_days' => (int) env('PBX3_BACKUP_GLACIER_AFTER_DAYS', 0),
        'legal_hold' => filter_var(env('PBX3_BACKUP_LEGAL_HOLD', false), FILTER_VALIDATE_BOOL),
    ],

    // Presigned GET for S3-only archives (S5.3).
    'backup_presigned_ttl_minutes' => (int) env('PBX3_BACKUP_PRESIGNED_TTL_MINUTES', 15),

    // Tenant export/import (S8.6) — paths on the PBX node.
    'tenant_export_dir' => env('PBX3_TENANT_EXPORT_DIR', '/opt/pbx3/bkup'),
    'tenant_schema_sql' => env('PBX3_TENANT_SCHEMA_SQL', '/opt/pbx3/db/db_sql/sqlite_create_tenant.sql'),
    'tenant_sounds_root' => env('PBX3_TENANT_SOUNDS_ROOT', '/usr/share/asterisk/sounds'),
    // Custom MOH lives at {tenant_moh_root}/moh-{shortuid} (GenAst / TenantController).
    // System /usr/share/asterisk/moh is instance-owned — not packed on tenant export.
    'tenant_moh_root' => env('PBX3_TENANT_MOH_ROOT', '/usr/share/asterisk'),
    'tenant_recordings_root' => env('PBX3_TENANT_RECORDINGS_ROOT', '/opt/pbx3/media/recordings'),

];
