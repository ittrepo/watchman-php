<?php

if (is_file(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
}

spl_autoload_register(function ($class) {
    foreach (['Watchman\\Tests\\' => __DIR__.'/', 'Watchman\\' => __DIR__.'/../src/'] as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
