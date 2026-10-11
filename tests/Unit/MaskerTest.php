<?php

namespace Watchman\Tests\Unit;

use Watchman\Config;
use Watchman\Support\Masker;
use Watchman\Tests\TestCase;

class MaskerTest extends TestCase
{
    private function masker(): Masker
    {
        $c = new Config();

        return new Masker($c->get('mask_keys'), $c->get('mask_patterns'));
    }

    public function test_sensitive_keys_are_replaced_whole(): void
    {
        $out = $this->masker()->maskArray(['db_password' => 'x', 'apiToken' => 'y', 'nested' => ['Authorization' => 'Bearer z'], 'user' => 'ok']);

        $this->assertSame('[REDACTED]', $out['db_password']);
        $this->assertSame('[REDACTED]', $out['apiToken']);
        $this->assertSame('[REDACTED]', $out['nested']['Authorization']);
        $this->assertSame('ok', $out['user']);
    }

    public function test_values_in_free_text(): void
    {
        $m = $this->masker();

        $this->assertSame('card [REDACTED] declined', $m->maskString('card 4111 1111 1111 1111 declined'));
        $this->assertSame('<a>[REDACTED]</a>', $m->maskString('<a><ns1:Password type="x">s3cr3t</ns1:Password></a>'));
        $this->assertSame('header [REDACTED]', $m->maskString('header Bearer abcdefghijklmnop'));
        $this->assertSame("UPDATE u SET password=[REDACTED] WHERE id=1", $m->maskString("UPDATE u SET password='hunter2' WHERE id=1"));
        $this->assertSame('key [REDACTED]', $m->maskString('key wm_sk_'.str_repeat('a', 40)));
    }

    public function test_short_numbers_are_left_alone(): void
    {
        $this->assertSame('booking 123456 failed after 30000ms', $this->masker()->maskString('booking 123456 failed after 30000ms'));
    }
}
