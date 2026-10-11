<?php

namespace Watchman\Tests\Unit;

use Watchman\Tests\TestCase;

/**
 * Handlers are tested in a separate PHP process: what matters is what PHP does
 * (output, exit code) with the SDK installed versus without it.
 */
class HandlersTest extends TestCase
{
    /** @return array{out: string, err: string, code: int, events: list<array<string, mixed>>} */
    private function scenario(string $name, string $killSwitch = ''): array
    {
        $spool = $this->tempDir();
        $cmd = [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/../Fixtures/scenario.php', $name, $spool, $killSwitch];

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['out' => (string) $out, 'err' => (string) $err, 'code' => $code, 'events' => $this->spooledEvents($spool)];
    }

    public function test_uncaught_exception_is_recorded_and_php_behaves_as_without_sdk(): void
    {
        $r = $this->scenario('uncaught');

        $this->assertSame(255, $r['code'], 'exit code must stay 255');
        // Same text PHP prints without the SDK ("PHP " prefix only goes to the log).
        $this->assertStringContainsString('Fatal error: Uncaught RuntimeException: deep failure 42', $r['err']);
        $this->assertStringNotContainsString("end\n", $r['out']);

        $this->assertCount(1, $r['events'], 'recorded once, not again by the shutdown handler');
        $e = $r['events'][0];
        $this->assertSame('EXCEPTION', $e['type']);
        $this->assertSame('CRITICAL', $e['level']);
        $this->assertSame('RuntimeException', $e['exception_class']);
        $this->assertSame('tests/Fixtures/scenario.php', $e['file']);
        $this->assertSame('level3', $e['stack_frames'][0]['function']);
        $this->assertSame('level2', $e['stack_frames'][1]['function']);
        $this->assertTrue($e['stack_frames'][0]['in_app']);
        $this->assertFalse($e['context']['handled']);
    }

    public function test_warning_is_recorded_and_still_printed_by_php(): void
    {
        $r = $this->scenario('warning');

        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('Undefined array key "missing"', $r['err'].$r['out']);
        $this->assertStringContainsString("after-warning\n", $r['out']);

        $this->assertCount(1, $r['events'], '@-suppressed warning must not be recorded');
        $this->assertSame('PHP_WARNING', $r['events'][0]['type']);
        $this->assertSame('WARNING', $r['events'][0]['level']);
        $this->assertNotEmpty($r['events'][0]['code_context']['lines']);
    }

    public function test_same_warning_in_a_loop_is_capped(): void
    {
        $r = $this->scenario('loop');

        $this->assertCount(3, $r['events']);
    }

    public function test_previous_error_handler_still_runs(): void
    {
        $r = $this->scenario('chain');

        $this->assertStringContainsString("previous-handler-called\n", $r['out']);
        $this->assertStringNotContainsString('Undefined array key', $r['err'], 'previous handler said handled; PHP must not print');
        $this->assertCount(1, $r['events']);
    }

    public function test_out_of_memory_fatal_is_recorded(): void
    {
        $r = $this->scenario('fatal');

        $this->assertSame(255, $r['code']);
        $this->assertCount(1, $r['events']);
        $this->assertSame('PHP_FATAL', $r['events'][0]['type']);
        $this->assertSame('CRITICAL', $r['events'][0]['level']);
        $this->assertStringContainsString('Allowed memory size', $r['events'][0]['message']);
    }

    public function test_deprecations_are_sampled_out_by_default(): void
    {
        $r = $this->scenario('deprecated');

        $this->assertCount(1, $r['events']);
        $this->assertSame('PHP_USER_WARNING', $r['events'][0]['type']);
    }

    public function test_kill_switch_means_no_handlers_and_no_files(): void
    {
        $kill = $this->tempDir().'/disabled';
        touch($kill);

        $r = $this->scenario('handlers-untouched', $kill);

        $this->assertStringContainsString("client=null\n", $r['out']);
        $this->assertStringContainsString("previous=null\n", $r['out'], 'no error handler installed');
        $this->assertSame([], $r['events']);
    }

    public function test_without_kill_switch_the_handler_is_installed(): void
    {
        $r = $this->scenario('handlers-untouched');

        $this->assertStringContainsString("previous=set\n", $r['out']);
    }
}
