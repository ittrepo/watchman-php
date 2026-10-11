<?php

namespace Watchman\Tests\Unit;

use Watchman\Config;
use Watchman\Stacktrace;
use Watchman\Tests\TestCase;

class StacktraceTest extends TestCase
{
    public function test_relative_paths_and_in_app(): void
    {
        $s = new Stacktrace(new Config(['project' => 't', 'app_root' => '/var/www/itt']));

        $this->assertSame('class.booking.php', $s->relative('/var/www/itt/class.booking.php'));
        $this->assertTrue($s->isInApp('/var/www/itt/class.booking.php'));
        $this->assertFalse($s->isInApp('/var/www/itt/vendor/guzzle/Client.php'));
        $this->assertFalse($s->isInApp('/usr/share/php/Other.php'));
        $this->assertSame('/usr/share/php/Other.php', $s->relative('/usr/share/php/Other.php'));
    }

    public function test_code_context_window(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/f.php', implode("\n", array_map(function ($n) { return "line $n"; }, range(1, 20))));
        $s = new Stacktrace(new Config(['project' => 't', 'code_context_lines' => 2]));

        $ctx = $s->codeContext($dir.'/f.php', 10);
        $this->assertSame(8, $ctx['start_line']);
        $this->assertSame(['line 8', 'line 9', 'line 10', 'line 11', 'line 12'], $ctx['lines']);
        $this->assertSame(['line 1', 'line 2', 'line 3'], $s->codeContext($dir.'/f.php', 1)['lines']);
        $this->assertNull($s->codeContext($dir.'/missing.php', 1));
    }

    public function test_big_files_are_skipped(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/big.php', str_repeat("x\n", 5000));
        $s = new Stacktrace(new Config(['project' => 't', 'max_source_file_bytes' => 1000]));

        $this->assertNull($s->codeContext($dir.'/big.php', 3));
    }

    public function test_frames_are_capped(): void
    {
        $s = new Stacktrace(new Config(['project' => 't', 'max_frames' => 5]));
        $trace = array_fill(0, 50, ['file' => '/a.php', 'line' => 1, 'function' => 'f']);

        $this->assertCount(5, $s->frames($trace, '/a.php', 1));
    }

    public function test_in_app_exclude_marks_wrappers_as_not_in_app(): void
    {
        $s = new Stacktrace(new Config(['project' => 't', 'app_root' => '/var/www/itt', 'in_app_exclude' => ['src/Db.php', 'lib/log/']]));

        $this->assertFalse($s->isInApp('/var/www/itt/src/Db.php'));
        $this->assertFalse($s->isInApp('/var/www/itt/lib/log/Logger.php'));
        $this->assertTrue($s->isInApp('/var/www/itt/src/UserRepository.php'));
    }
}
