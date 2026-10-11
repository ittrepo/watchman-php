<?php

namespace Watchman\Tests;

use Watchman\Watchman;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var list<string> */
    private $tempDirs = [];

    protected function tearDown(): void
    {
        Watchman::reset();
        foreach ($this->tempDirs as $dir) {
            self::rmrf($dir);
        }
        parent::tearDown();
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/watchman-test-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** @return list<array<string, mixed>> */
    protected function spooledEvents(string $spool, string $project = 'test'): array
    {
        $events = [];
        $files = glob($spool.'/'.$project.'/*.jsonl') ?: [];
        sort($files);
        foreach ($files as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $events[] = json_decode($line, true);
            }
        }

        return $events;
    }

    private static function rmrf(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::rmrf($path.'/'.$entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }
}
