#!/usr/bin/env php
<?php

/*
 * Watchman shipper: sends spooled events to the Watchman server.
 *
 *   php vendor/bin/ship.php --config=/etc/watchman/ship.php
 *
 * Cron (as the same user PHP-FPM runs as, so both can use the spool):
 *   * * * * * php /var/www/itt/vendor/bin/ship.php --config=/etc/watchman/ship.php >> /var/log/watchman-ship.log 2>&1
 *
 * Exit codes: 0 ok / locked / disabled, 1 config error.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

foreach ([__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

if (! class_exists('Watchman\\Shipper\\Shipper')) {
    spl_autoload_register(function ($class) {
        if (strpos($class, 'Watchman\\') === 0) {
            $file = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, 9)).'.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

$options = getopt('', ['config:', 'verbose']);
$configFile = isset($options['config']) ? (string) $options['config'] : '/etc/watchman/ship.php';

if (! is_file($configFile) || ! is_readable($configFile)) {
    fwrite(STDERR, "watchman-ship: config not readable: $configFile\n");
    exit(1);
}

$config = require $configFile;
if (! is_array($config)) {
    fwrite(STDERR, "watchman-ship: $configFile must return an array\n");
    exit(1);
}

$result = (new Watchman\Shipper\Shipper($config))->run();

if (isset($options['verbose'])) {
    fwrite(STDOUT, json_encode($result)."\n");
}

exit($result['status'] === 'config_error' ? 1 : 0);
