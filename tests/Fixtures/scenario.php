<?php

/*
 * Runs one SDK scenario in a fresh PHP process (handlers, shutdown and exit
 * codes cannot be tested inside PHPUnit's own process).
 *
 *   php scenario.php <name> <spool_dir> [kill_switch_file]
 */

require __DIR__.'/../bootstrap.php';

use Watchman\Watchman;

$scenario = $argv[1];
$spool = $argv[2];
$kill = $argv[3] ?? '';

ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
error_reporting(E_ALL);

$previousCalled = false;
if ($scenario === 'chain') {
    set_error_handler(function () use (&$previousCalled) {
        $previousCalled = true;
        fwrite(STDOUT, "previous-handler-called\n");

        return true; // handled: PHP must not print it
    });
}

$client = Watchman::init([
    'project' => 'test',
    'spool_dir' => $spool,
    'kill_switch_file' => $kill,
    'app_root' => dirname(__DIR__, 2),
    'flush' => 'shutdown',
]);

fwrite(STDOUT, 'client='.($client === null ? 'null' : 'ok')."\n");

function level3() { throw new RuntimeException('deep failure 42'); }
function level2() { level3(); }

switch ($scenario) {
    case 'uncaught':
        level2();
        break;

    case 'warning':
        $a = [];
        $x = $a['missing'];          // captured
        $y = @$a['suppressed'];      // @: not captured
        fwrite(STDOUT, "after-warning\n");
        break;

    case 'loop':
        $a = [];
        for ($i = 0; $i < 20; $i++) {
            $x = @$a['quiet'];
            $y = $a['noisy'];
        }
        break;

    case 'chain':
        $a = [];
        $x = $a['missing'];
        break;

    case 'fatal':
        ini_set('memory_limit', '16M');
        $blocks = [];
        while (true) {
            $blocks[] = str_repeat('x', 1024 * 1024);
        }

    case 'deprecated':
        trigger_error('old api', E_USER_DEPRECATED);
        trigger_error('custom warning', E_USER_WARNING);
        break;

    case 'handlers-untouched':
        $current = set_error_handler(function () { return false; });
        fwrite(STDOUT, 'previous='.($current === null ? 'null' : 'set')."\n");
        break;
}

fwrite(STDOUT, "end\n");
