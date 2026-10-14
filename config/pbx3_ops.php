<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fleet ops — misconfigured phone REGISTER loops (whitelist-gated)
    |--------------------------------------------------------------------------
    |
    | Scans Asterisk security log for auth failures whose source IP is in
    | Fail2ban ignoreip (pbx3-jails.conf). Emits to Gatekeeper notify mail.
    | Do not ban those IPs — ops fix the handset.
    | Default: /var/log/asterisk/security (split from messages). Override with
    | PBX3_OPS_ASTERISK_SECURITY or legacy PBX3_OPS_ASTERISK_MESSAGES.
    |
    | Enable with PBX3_OPS_REGISTER_LOOP_ENABLED=true and Gatekeeper URL/token
    | (same PBX3_GATEKEEPER_* as recordings, or dedicated overrides below).
    |
    */

    'register_loop_enabled' => filter_var(env('PBX3_OPS_REGISTER_LOOP_ENABLED', false), FILTER_VALIDATE_BOOL),

    'asterisk_messages_path' => env(
        'PBX3_OPS_ASTERISK_SECURITY',
        env('PBX3_OPS_ASTERISK_MESSAGES', '/var/log/asterisk/security')
    ),

    'fail2ban_jail_path' => env(
        'PBX3_OPS_FAIL2BAN_JAIL',
        '/etc/fail2ban/jail.d/pbx3-jails.conf'
    ),

    'state_path' => env('PBX3_OPS_REGISTER_STATE', storage_path('app/ops-register-loop.json')),

    /** Failures in window before emit (align with asterisk jail maxretry). */
    'threshold' => (int) env('PBX3_OPS_REGISTER_THRESHOLD', 5),

    'window_seconds' => (int) env('PBX3_OPS_REGISTER_WINDOW', 600),

    /** Local cooldown before re-emit for same ext|ip (Gatekeeper also throttles). */
    'emit_cooldown_seconds' => (int) env('PBX3_OPS_REGISTER_COOLDOWN', 1800),

    'gatekeeper_url' => env('PBX3_OPS_GATEKEEPER_URL', env('PBX3_GATEKEEPER_URL', '')),

    'gatekeeper_token' => env('PBX3_OPS_GATEKEEPER_TOKEN', env('PBX3_GATEKEEPER_TOKEN', '')),

    'gatekeeper_http_verify' => filter_var(
        env('PBX3_OPS_GATEKEEPER_HTTP_VERIFY', env('PBX3_GATEKEEPER_HTTP_VERIFY', true)),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Fleet ops — Egress Unavail (PJSIP qualify)
    |--------------------------------------------------------------------------
    |
    | Polls AMI ContactStatusDetail via FleetPostureService. Emits Gatekeeper
    | ops-events on Avail↔Unavail (hysteresis; first run seeds without mail).
    |
    */

    'egress_unavail_notify_enabled' => filter_var(
        env('PBX3_OPS_EGRESS_UNAVAIL_NOTIFY', false),
        FILTER_VALIDATE_BOOL
    ),

    'egress_state_path' => env('PBX3_OPS_EGRESS_STATE', storage_path('app/ops-egress-qualify.json')),

    /** Consecutive Unavail ticks before down notify (mirror Gatekeeper /up misses). */
    'egress_miss_threshold' => (int) env('PBX3_OPS_EGRESS_MISS_THRESHOLD', 2),

    /*
    |--------------------------------------------------------------------------
    | Fleet ops — toll fraud / velocity (V1–V2; V5 auto-block later)
    |--------------------------------------------------------------------------
    |
    | Query helper reads Phase 6 master.db. Lab fixture uses prefix 0900…
    | Scanner: pbx3:ops-velocity → Gatekeeper velocity_irsf.
    | Spec: FLEET_TOLL_FRAUD_VELOCITY_REQUIREMENTS.md
    |
    */

    'velocity_enabled' => filter_var(env('PBX3_OPS_VELOCITY_ENABLED', false), FILTER_VALIDATE_BOOL),

    /** Count threshold N (V2). */
    'velocity_threshold' => (int) env('PBX3_OPS_VELOCITY_N', 10),

    /** Window minutes T. */
    'velocity_window_minutes' => (int) env('PBX3_OPS_VELOCITY_T', 5),

    /** Quiet / hysteresis minutes Q (V2). */
    'velocity_quiet_minutes' => (int) env('PBX3_OPS_VELOCITY_Q', 30),

    /** Comma-separated high-cost destination prefixes (lab default matches fixture). */
    'velocity_prefixes' => env('PBX3_OPS_VELOCITY_PREFIXES', '0900,+44900,0044900'),

    'velocity_state_path' => env('PBX3_OPS_VELOCITY_STATE', storage_path('app/ops-velocity.json')),

    /** Optional lab/test overrides (else Sysglobal id/fqdn). */
    'velocity_instance_id' => env('PBX3_OPS_VELOCITY_INSTANCE_ID', ''),
    'velocity_instance_fqdn' => env('PBX3_OPS_VELOCITY_INSTANCE_FQDN', ''),

    /** V5: flip ipphone.active=NO + clear CF + hangup + genAst when attributed. */
    'velocity_act_enabled' => filter_var(env('PBX3_OPS_VELOCITY_ACT', false), FILTER_VALIDATE_BOOL),

    /** Comma-separated pkey/shortuid that never auto-deactivate. */
    'velocity_allowlist' => env('PBX3_OPS_VELOCITY_ALLOWLIST', ''),

    /** Unit tests: skip asterisk CLI / genAst (DB act still runs). */
    'velocity_skip_asterisk' => filter_var(env('PBX3_OPS_VELOCITY_SKIP_ASTERISK', false), FILTER_VALIDATE_BOOL),

    /**
     * V3 policy source: fleet (S3 catalog/velocity-policy.json → cache → env) or local (env only).
     * Spec: FLEET_TOLL_FRAUD_VELOCITY_IMPLEMENTATION_PLAN.md L1.
     */
    'velocity_policy_mode' => env('PBX3_OPS_VELOCITY_POLICY', 'fleet'),

    'velocity_policy_cache_path' => env('PBX3_OPS_VELOCITY_POLICY_CACHE', storage_path('app/ops-velocity-policy.json')),

    /** Filled at runtime by VelocityPolicyResolver when fleet policy applies. */
    'velocity_fleet_policy' => null,

    /**
     * WP1 off-hours detector (high-risk prefixes inside policy windows).
     * Fleet policy detectors.off_hours / off_hours.* override when mode=fleet.
     */
    'velocity_off_hours_enabled' => filter_var(env('PBX3_OPS_VELOCITY_OFF_HOURS', false), FILTER_VALIDATE_BOOL),
    'velocity_off_hours_tz' => env('PBX3_OPS_VELOCITY_OFF_HOURS_TZ', 'UTC'),
    'velocity_off_hours_threshold' => (int) env('PBX3_OPS_VELOCITY_OFF_HOURS_N', 20),
    'velocity_off_hours_window_minutes' => (int) env('PBX3_OPS_VELOCITY_OFF_HOURS_T', 60),
    /** @var list<array{dow:list<int>, start:string, end:string}> */
    'velocity_off_hours_windows' => [
        ['dow' => [6, 0], 'start' => '18:00', 'end' => '06:00'],
    ],

    /*
    |--------------------------------------------------------------------------
    | High-risk CoS seed (prevention)
    |--------------------------------------------------------------------------
    |
    | Spec: HIGH_RISK_DIAL_BLOCK_POSTURE.md
    | Patterns: config/cos/highrisk-{uk|us}-starter.dialplan
    |
    */
    'cos_highrisk_seed' => filter_var(env('PBX3_COS_HIGHRISK_SEED', false), FILTER_VALIDATE_BOOL),
    'cos_highrisk_locale' => env('PBX3_COS_HIGHRISK_LOCALE', 'uk'),

];
