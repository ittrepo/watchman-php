<?php

namespace Watchman\Tests\Unit;

use Watchman\Tests\TestCase;
use Watchman\Transport\SpoolTransport;

class SpoolTransportTest extends TestCase
{
    public function test_writes_one_json_line_per_event_with_no_temp_left(): void
    {
        $spool = $this->tempDir();
        (new SpoolTransport($spool))->send('test', [['message' => 'a'], ['message' => "b ünïcödé \xB1"]]);

        $files = glob($spool.'/test/*.jsonl');
        $this->assertCount(1, $files);
        $this->assertSame([], glob($spool.'/test/.*.tmp'));
        $events = $this->spooledEvents($spool);
        $this->assertSame('a', $events[0]['message']);
        $this->assertStringStartsWith('b ünïcödé', $events[1]['message'], 'invalid UTF-8 is substituted, not dropped');
    }

    public function test_more_than_100_events_are_split_across_files(): void
    {
        $spool = $this->tempDir();
        (new SpoolTransport($spool))->send('test', array_fill(0, 250, ['message' => 'x']));

        $this->assertCount(3, glob($spool.'/test/*.jsonl'));
        $this->assertCount(250, $this->spooledEvents($spool));
    }

    public function test_unwritable_spool_returns_false_without_throwing(): void
    {
        $file = $this->tempDir().'/not-a-dir';
        touch($file);

        $previous = ini_set('error_log', '/dev/null');
        $result = (new SpoolTransport($file))->send('test', [['message' => 'x']]);
        ini_set('error_log', (string) $previous);

        $this->assertFalse($result);
    }
}
