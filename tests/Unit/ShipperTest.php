<?php

namespace Watchman\Tests\Unit;

use Watchman\Shipper\HttpSender;
use Watchman\Shipper\Shipper;
use Watchman\Tests\TestCase;
use Watchman\Transport\SpoolTransport;

class ShipperTest extends TestCase
{
    /** @var string */
    private $spool;

    /** @var FakeSender */
    private $sender;

    /** @var list<string> */
    private $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spool = $this->tempDir();
        $this->sender = new FakeSender();
    }

    /** @param array<string, mixed> $config */
    private function ship(array $config = []): array
    {
        $log = &$this->log;

        return (new Shipper($config + [
            'endpoint' => 'https://logs.test/',
            'server' => ['name' => 'VPS-01', 'hostname' => 'vps-01'],
            'spool_dir' => $this->spool,
            'projects' => ['itt' => 'wm_sk_key'],
            'kill_switch_file' => '',
        ], $this->sender, function ($line) use (&$log) {
            $log[] = $line;
        }))->run();
    }

    private function spoolEvents(int $files, int $perFile, string $project = 'itt'): void
    {
        $t = new SpoolTransport($this->spool);
        for ($f = 0; $f < $files; $f++) {
            $t->send($project, array_fill(0, $perFile, ['event_id' => 'e', 'message' => 'm']));
        }
    }

    private function remaining(string $project = 'itt'): int
    {
        return count(glob($this->spool.'/'.$project.'/*.jsonl') ?: []);
    }

    public function test_accepted_batches_are_deleted(): void
    {
        $this->spoolEvents(3, 10);

        $result = $this->ship();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(30, $result['sent']);
        $this->assertSame(0, $this->remaining());
        $batch = $this->sender->requests[0];
        $this->assertSame('https://logs.test/api/v1/errors/batch', $batch['url']);
        $this->assertSame('wm_sk_key', $batch['headers']['X-Project-Key']);
        $this->assertSame('VPS-01', $batch['payload']['server']['name']);
        $this->assertSame('watchman-php', $batch['payload']['server']['sdk_name']);
        $this->assertCount(30, $batch['payload']['events'], 'small files are combined into one batch');
    }

    public function test_batches_never_exceed_100_events(): void
    {
        $this->spoolEvents(5, 40);

        $this->ship();

        $batches = array_filter($this->sender->requests, function ($r) {
            return substr($r['url'], -6) === '/batch';
        });
        foreach ($batches as $r) {
            $this->assertLessThanOrEqual(100, count($r['payload']['events']));
        }
        $this->assertSame(200, array_sum(array_map(function ($r) {
            return count($r['payload']['events']);
        }, $batches)));
        $this->assertSame(0, $this->remaining());
    }

    public function test_server_errors_keep_files_for_the_next_run(): void
    {
        $this->spoolEvents(2, 5);
        $this->sender->statuses = [503];

        $result = $this->ship();

        $this->assertSame(2, $this->remaining());
        $this->assertGreaterThan(0, $result['retry_later']);
        $this->assertStringContainsString('will retry next run', implode("\n", $this->log));
    }

    public function test_rate_limit_keeps_files(): void
    {
        $this->spoolEvents(1, 5);
        $this->sender->statuses = [429];

        $this->ship();

        $this->assertSame(1, $this->remaining());
    }

    public function test_network_failure_keeps_files(): void
    {
        $this->spoolEvents(1, 5);
        $this->sender->statuses = [0];

        $this->ship();

        $this->assertSame(1, $this->remaining());
    }

    public function test_permanent_rejection_moves_files_to_failed(): void
    {
        $this->spoolEvents(2, 5);
        $this->sender->statuses = [401];

        $result = $this->ship();

        $this->assertSame(0, $this->remaining());
        $this->assertCount(2, glob($this->spool.'/itt/failed/*.jsonl'));
        $this->assertSame(2, $result['failed_files']);
    }

    public function test_project_without_a_key_is_left_alone(): void
    {
        $this->spoolEvents(1, 5, 'unknown');

        $this->ship();

        $this->assertSame(1, $this->remaining('unknown'));
        $this->assertStringContainsString('no API key for project "unknown"', implode("\n", $this->log));
    }

    public function test_heartbeat_is_sent_every_run(): void
    {
        $this->ship();

        $this->assertCount(1, $this->sender->requests);
        $this->assertSame('https://logs.test/api/v1/heartbeat', $this->sender->requests[0]['url']);
        $this->assertSame('VPS-01', $this->sender->requests[0]['payload']['name']);
    }

    public function test_kill_switch_stops_shipping(): void
    {
        $this->spoolEvents(1, 5);
        $kill = $this->tempDir().'/disabled';
        touch($kill);

        $result = $this->ship(['kill_switch_file' => $kill]);

        $this->assertSame('disabled', $result['status']);
        $this->assertSame([], $this->sender->requests);
        $this->assertSame(1, $this->remaining());
    }

    public function test_a_second_run_while_locked_does_nothing(): void
    {
        $this->spoolEvents(1, 5);
        $lock = fopen($this->spool.'/.ship.lock', 'c');
        flock($lock, LOCK_EX);

        $result = $this->ship();

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame('locked', $result['status']);
        $this->assertSame(1, $this->remaining());
    }

    public function test_spool_cap_drops_oldest_files_first(): void
    {
        mkdir($this->spool.'/itt');
        file_put_contents($this->spool.'/itt/20260101000000-1-a.jsonl', str_repeat('{"m":1}'."\n", 80000));
        file_put_contents($this->spool.'/itt/20260102000000-1-b.jsonl', '{"m":2}'."\n");
        $this->sender->statuses = [503];

        $result = $this->ship(['max_spool_mb' => 0.5]);

        $this->assertSame(1, $result['dropped_for_space']);
        $this->assertFileDoesNotExist($this->spool.'/itt/20260101000000-1-a.jsonl');
        $this->assertFileExists($this->spool.'/itt/20260102000000-1-b.jsonl');
    }

    public function test_unreadable_lines_are_skipped_and_empty_files_fail(): void
    {
        mkdir($this->spool.'/itt');
        file_put_contents($this->spool.'/itt/20260101000000-1-a.jsonl', "not json\n{\"message\":\"ok\"}\n");
        file_put_contents($this->spool.'/itt/20260101000001-1-b.jsonl', "garbage\n");

        $result = $this->ship();

        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, glob($this->spool.'/itt/failed/*.jsonl'));
    }
}

final class FakeSender implements HttpSender
{
    /** @var list<array<string, mixed>> */
    public $requests = [];

    /** @var list<int> Status per request; the last one repeats. Default 202. */
    public $statuses = [];

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $payload = json_decode($body, true);
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];
        $status = $this->statuses === [] ? 202 : (count($this->statuses) > 1 ? array_shift($this->statuses) : $this->statuses[0]);

        if (substr($url, -10) === '/heartbeat') {
            $status = 200;
        }

        $accepted = isset($payload['events']) ? count($payload['events']) : 0;

        return [
            'status' => $status,
            'body' => $status === 202 ? json_encode(['accepted' => $accepted, 'rejected' => 0]) : '{"error":"x"}',
            'error' => $status === 0 ? 'connection refused' : null,
        ];
    }
}
