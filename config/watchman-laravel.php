<?php

/*
 * Laravel adapter config: `php artisan vendor:publish --tag=watchman-config`
 * Any option from Watchman\Config::defaults() can be added here.
 */
return [
    'enabled' => env('WATCHMAN_ENABLED', true),

    // Spool sub-folder; ship.php maps it to the API key. Empty = SDK off.
    'project' => env('WATCHMAN_PROJECT'),

    'environment' => env('WATCHMAN_ENVIRONMENT'),   // default: app()->environment()
    'release' => env('WATCHMAN_RELEASE'),           // set from the deploy tag
    'app_root' => null,                           // default: base_path()

    'spool_dir' => env('WATCHMAN_SPOOL_DIR', '/var/spool/watchman'),
    'kill_switch_file' => env('WATCHMAN_KILL_SWITCH', '/etc/watchman/disabled'),

    // Every query as a breadcrumb (SQL text only, never bindings).
    'sql_breadcrumbs' => env('WATCHMAN_SQL_BREADCRUMBS', true),

    'sample_rates' => [
        'PHP_DEPRECATED' => 0.0,
    ],

    // Exceptions Laravel already does not report (404, validation, auth) never
    // reach Watchman. Add more classes here.
    'ignore_exceptions' => [],
];
