<?php

/*
 * Copy to /etc/watchman/ship.php (mode 0640, owner root:www-data).
 * Holds the API keys; the SDK itself never sees them.
 */
return [
    'endpoint' => 'https://logs.example.com',

    // Name shown on the dashboard. Unique per VPS.
    'server' => [
        'name' => 'VPS-DHAKA-01',
        // 'hostname' => gethostname(),   // default
        // 'ip' => '10.0.1.11',           // default: server sees the source IP
    ],

    // Must match the SDK's spool_dir.
    'spool_dir' => '/var/spool/watchman',

    // SDK `project` => secret key (wm_sk_...) from the Watchman dashboard.
    'projects' => [
        'itt-ota' => 'wm_sk_replace_me',
    ],

    'kill_switch_file' => '/etc/watchman/disabled',
    'max_runtime_seconds' => 50,      // stay under the 1-minute cron interval
    'timeout_seconds' => 10,
    'max_events_per_batch' => 100,    // server limit
    'max_batch_bytes' => 200000,      // server limit is 256 KB
    'max_spool_mb' => 512,            // oldest files dropped above this
    'failed_retention_days' => 7,
    'heartbeat' => true,
];
