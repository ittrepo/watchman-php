<?php

namespace Watchman\Transport;

/**
 * Writes events to the local spool: no network on the request path.
 *
 *   {spool_dir}/{project}/{YmdHis}-{pid}-{random}.jsonl   one JSON event per line
 *
 * Each flush is written to a dot-file and renamed into place, so bin/ship.php
 * (which only reads *.jsonl) never sees a half-written file.
 */
final class SpoolTransport implements TransportInterface
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** @var string */
    private $spoolDir;

    /** @var bool */
    private $warned = false;

    public function __construct(string $spoolDir)
    {
        $this->spoolDir = rtrim($spoolDir, '/\\');
    }

    /** Server accepts at most 100 events per batch; ship.php sends whole files. */
    private const MAX_EVENTS_PER_FILE = 100;

    public function send(string $project, array $events): bool
    {
        if ($events === []) {
            return true;
        }

        $ok = true;
        foreach (array_chunk($events, self::MAX_EVENTS_PER_FILE) as $chunk) {
            $ok = $this->writeFile($project, $chunk) && $ok;
        }

        return $ok;
    }

    /** @param list<array<string, mixed>> $events */
    private function writeFile(string $project, array $events): bool
    {
        $lines = '';
        foreach ($events as $event) {
            $json = json_encode($event, self::JSON_FLAGS);
            if (is_string($json)) {
                $lines .= $json."\n";
            }
        }

        if ($lines === '') {
            return false;
        }

        $dir = $this->spoolDir.'/'.$project;

        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            return $this->fail("cannot create spool directory $dir");
        }

        try {
            $name = sprintf('%s-%d-%s', gmdate('YmdHis'), getmypid(), bin2hex(random_bytes(4)));
        } catch (\Throwable $e) {
            $name = sprintf('%s-%d-%d', gmdate('YmdHis'), getmypid(), mt_rand());
        }

        $tmp = $dir.'/.'.$name.'.tmp';
        $final = $dir.'/'.$name.'.jsonl';

        if (@file_put_contents($tmp, $lines, LOCK_EX) === false) {
            return $this->fail("cannot write $tmp");
        }

        @chmod($tmp, 0660);

        if (! @rename($tmp, $final)) {
            @unlink($tmp);

            return $this->fail("cannot rename $tmp");
        }

        return true;
    }

    /** One error_log line per process, never an exception: logging must not break the app. */
    private function fail(string $why): bool
    {
        if (! $this->warned) {
            $this->warned = true;
            @error_log('[watchman] spool write failed: '.$why);
        }

        return false;
    }
}
