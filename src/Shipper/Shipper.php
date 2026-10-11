<?php

namespace Watchman\Shipper;

use Watchman\Watchman;

/**
 * Delivers spooled events to the Watchman server. Run by cron every minute:
 *
 *   * * * * * www-data php /path/vendor/bin/ship.php --config=/etc/watchman/ship.php
 *
 * Response handling (matches the server contract):
 *   2xx                  delivered      -> delete the files
 *   400/401/403/404/413  will never work -> move to {project}/failed/, log
 *   429 / 5xx / network  try again       -> keep, stop this project for this run
 */
final class Shipper
{
    private const PERMANENT_FAILURES = [400, 401, 403, 404, 405, 413, 422];

    /** @var array<string, mixed> */
    private $config;

    /** @var HttpSender */
    private $sender;

    /** @var callable(string): void */
    private $logger;

    /** @var array<string, int> */
    private $stats = ['sent' => 0, 'rejected' => 0, 'failed_files' => 0, 'retry_later' => 0, 'dropped_for_space' => 0];

    /**
     * @param array<string, mixed>       $config
     * @param callable(string): void|null $logger
     */
    public function __construct(array $config, ?HttpSender $sender = null, ?callable $logger = null)
    {
        $this->config = array_replace([
            'endpoint' => '',
            'server' => [],
            'spool_dir' => '/var/spool/watchman',
            'projects' => [],
            'kill_switch_file' => '/etc/watchman/disabled',
            'max_runtime_seconds' => 50,
            'timeout_seconds' => 10,
            'max_events_per_batch' => 100,
            'max_batch_bytes' => 200000,
            'max_spool_mb' => 512,
            'failed_retention_days' => 7,
            'heartbeat' => true,
        ], $config);

        $this->config['endpoint'] = rtrim((string) $this->config['endpoint'], '/');
        $this->config['spool_dir'] = rtrim((string) $this->config['spool_dir'], '/');
        $this->sender = $sender ?? (function_exists('curl_init') ? new CurlSender() : new StreamSender());
        $this->logger = $logger ?? function (string $line): void {
            fwrite(STDERR, $line."\n");
        };
    }

    /** @return array<string, int|string> */
    public function run(): array
    {
        $spool = (string) $this->config['spool_dir'];

        if ($this->config['endpoint'] === '' || ! is_array($this->config['projects'])) {
            $this->log('config error: endpoint and projects are required');

            return ['status' => 'config_error'] + $this->stats;
        }

        if (! is_dir($spool) && ! @mkdir($spool, 0770, true) && ! is_dir($spool)) {
            $this->log("cannot create spool dir $spool");

            return ['status' => 'no_spool'] + $this->stats;
        }

        $lock = @fopen($spool.'/.ship.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            // Previous run still going (slow server): skip, it will finish.
            return ['status' => 'locked'] + $this->stats;
        }

        try {
            $kill = (string) $this->config['kill_switch_file'];
            if ($kill !== '' && file_exists($kill)) {
                $this->log('kill switch present, not shipping');

                return ['status' => 'disabled'] + $this->stats;
            }

            $deadline = microtime(true) + (int) $this->config['max_runtime_seconds'];

            $this->cleanStaleTemp();
            $this->enforceSpoolCap();

            foreach ($this->projectDirs() as $project) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $this->shipProject($project, $deadline);
            }

            $this->heartbeat();
            $this->pruneFailed();

            return ['status' => 'ok'] + $this->stats;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function shipProject(string $project, float $deadline): void
    {
        $dir = $this->config['spool_dir'].'/'.$project;
        $files = glob($dir.'/*.jsonl') ?: [];
        sort($files);

        if ($files === []) {
            return;
        }

        $key = $this->config['projects'][$project] ?? null;
        if (! is_string($key) || $key === '') {
            $this->log(sprintf('no API key for project "%s"; leaving %d file(s)', $project, count($files)));

            return;
        }

        while ($files !== [] && microtime(true) < $deadline) {
            list($batchFiles, $events, $broken) = $this->nextBatch($files);

            foreach ($broken as $file) {
                $this->moveToFailed($project, $file, 'unreadable or empty');
            }

            if ($events === []) {
                continue;
            }

            $result = $this->post('/api/v1/errors/batch', $key, [
                'server' => $this->serverPayload(),
                'events' => $events,
            ]);
            $status = $result['status'];

            if ($status >= 200 && $status < 300) {
                $body = json_decode($result['body'], true);
                $this->stats['sent'] += (int) ($body['accepted'] ?? count($events));
                $this->stats['rejected'] += (int) ($body['rejected'] ?? 0);
                foreach ($batchFiles as $file) {
                    @unlink($file);
                }
                continue;
            }

            if (in_array($status, self::PERMANENT_FAILURES, true)) {
                $this->log(sprintf('project "%s": HTTP %d, %s; moving %d file(s) to failed/', $project, $status, substr($result['body'], 0, 200), count($batchFiles)));
                foreach ($batchFiles as $file) {
                    $this->moveToFailed($project, $file, "HTTP $status");
                }
                continue;
            }

            // 429, 5xx, network: the spool keeps them; next run retries.
            $this->stats['retry_later'] += count($batchFiles) + count($files);
            $this->log(sprintf('project "%s": %s; will retry next run', $project, $status === 0 ? 'network error: '.$result['error'] : "HTTP $status"));

            return;
        }
    }

    /**
     * Take whole files from the front of $files until the batch is full.
     * Always takes at least one file, so an oversized file cannot block the queue
     * (the server answers 413 and it goes to failed/).
     *
     * @param list<string> $files  consumed
     * @return array{0: list<string>, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function nextBatch(array &$files): array
    {
        $maxEvents = (int) $this->config['max_events_per_batch'];
        $maxBytes = (int) $this->config['max_batch_bytes'];
        $batchFiles = [];
        $events = [];
        $broken = [];
        $bytes = 0;

        while ($files !== []) {
            $file = $files[0];
            $size = (int) @filesize($file);
            $fileEvents = $this->readEvents($file);

            if ($fileEvents === []) {
                array_shift($files);
                $broken[] = $file;
                continue;
            }

            $fits = count($events) + count($fileEvents) <= $maxEvents && $bytes + $size <= $maxBytes;
            if ($batchFiles !== [] && ! $fits) {
                break;
            }

            array_shift($files);
            $batchFiles[] = $file;
            $bytes += $size;
            foreach ($fileEvents as $event) {
                $events[] = $event;
            }
        }

        return [$batchFiles, $events, $broken];
    }

    /** @return list<array<string, mixed>> */
    private function readEvents(string $file): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return [];
        }

        $events = [];
        while (($line = fgets($handle)) !== false) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }
        fclose($handle);

        return $events;
    }

    private function heartbeat(): void
    {
        $server = $this->serverPayload();
        if (! $this->config['heartbeat'] || empty($server['name'])) {
            return;
        }

        foreach ($this->config['projects'] as $key) {
            if (is_string($key) && $key !== '') {
                $result = $this->post('/api/v1/heartbeat', $key, $server);
                if ($result['status'] < 200 || $result['status'] >= 300) {
                    $this->log('heartbeat failed: '.($result['status'] === 0 ? (string) $result['error'] : 'HTTP '.$result['status']));
                }

                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string, error: string|null}
     */
    private function post(string $path, string $key, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $this->sender->post($this->config['endpoint'].$path, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Project-Key' => $key,
            'User-Agent' => 'watchman-php/'.Watchman::VERSION,
        ], (string) $body, (int) $this->config['timeout_seconds']);
    }

    /** @return array<string, string> */
    private function serverPayload(): array
    {
        $server = (array) $this->config['server'];

        return array_filter([
            'name' => isset($server['name']) ? (string) $server['name'] : '',
            'hostname' => isset($server['hostname']) ? (string) $server['hostname'] : (string) gethostname(),
            'ip' => isset($server['ip']) ? (string) $server['ip'] : null,
            'sdk_name' => 'watchman-php',
            'sdk_version' => Watchman::VERSION,
        ], function ($v) {
            return $v !== null && $v !== '';
        });
    }

    /** @return list<string> */
    private function projectDirs(): array
    {
        $dirs = [];
        foreach (scandir($this->config['spool_dir']) ?: [] as $entry) {
            if ($entry[0] !== '.' && is_dir($this->config['spool_dir'].'/'.$entry)) {
                $dirs[] = $entry;
            }
        }
        sort($dirs);

        return $dirs;
    }

    /** Disk safety: if the server is down for days, drop the oldest files first. */
    private function enforceSpoolCap(): void
    {
        $cap = (int) round((float) $this->config['max_spool_mb'] * 1024 * 1024);
        if ($cap <= 0) {
            return;
        }

        $all = [];
        $total = 0;
        foreach ($this->projectDirs() as $project) {
            foreach (glob($this->config['spool_dir'].'/'.$project.'/*.jsonl') ?: [] as $file) {
                $size = (int) @filesize($file);
                $all[basename($file).'|'.$file] = $size;
                $total += $size;
            }
        }

        if ($total <= $cap) {
            return;
        }

        ksort($all); // file names start with a UTC timestamp: oldest first
        $dropped = 0;
        foreach ($all as $id => $size) {
            if ($total <= $cap) {
                break;
            }
            $file = substr($id, strpos($id, '|') + 1);
            if (@unlink($file)) {
                $total -= $size;
                $dropped++;
            }
        }

        $this->stats['dropped_for_space'] += $dropped;
        $this->log(sprintf('spool over %s MB: dropped %d oldest file(s)', (string) $this->config['max_spool_mb'], $dropped));
    }

    /** A writer that crashed between write and rename leaves a .tmp behind. */
    private function cleanStaleTemp(): void
    {
        foreach ($this->projectDirs() as $project) {
            foreach (glob($this->config['spool_dir'].'/'.$project.'/.*.tmp') ?: [] as $file) {
                if (@filemtime($file) < time() - 3600) {
                    @unlink($file);
                }
            }
        }
    }

    private function moveToFailed(string $project, string $file, string $reason): void
    {
        $dir = $this->config['spool_dir'].'/'.$project.'/failed';
        if (! is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        if (! @rename($file, $dir.'/'.basename($file))) {
            @unlink($file);
        }

        $this->stats['failed_files']++;
        $this->log(sprintf('failed: %s (%s)', basename($file), $reason));
    }

    private function pruneFailed(): void
    {
        $limit = time() - (int) $this->config['failed_retention_days'] * 86400;

        foreach ($this->projectDirs() as $project) {
            foreach (glob($this->config['spool_dir'].'/'.$project.'/failed/*.jsonl') ?: [] as $file) {
                if (@filemtime($file) < $limit) {
                    @unlink($file);
                }
            }
        }
    }

    private function log(string $message): void
    {
        call_user_func($this->logger, '['.gmdate('Y-m-d H:i:s').'] watchman-ship: '.$message);
    }
}
