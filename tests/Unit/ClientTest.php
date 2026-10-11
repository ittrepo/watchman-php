<?php

namespace Watchman\Tests\Unit;

use Watchman\Client;
use Watchman\Config;
use Watchman\Watchman;
use Watchman\Tests\TestCase;
use Watchman\Transport\MemoryTransport;

class ClientTest extends TestCase
{
    /** @var MemoryTransport */
    private $transport;

    /** @param array<string, mixed> $options */
    private function client(array $options = []): Client
    {
        $this->transport = new MemoryTransport();

        return new Client(new Config($options + ['project' => 'test', 'flush' => 'shutdown', 'kill_switch_file' => '']), $this->transport);
    }

    public function test_events_are_buffered_until_flush_in_shutdown_mode(): void
    {
        $c = $this->client();
        $c->captureMessage('one');
        $c->captureMessage('two', 'warning');

        $this->assertSame([], $this->transport->events);

        $c->flush();
        $this->assertCount(2, $this->transport->events);
        $this->assertSame(1, $this->transport->sends, 'one write per flush');
        $this->assertSame('WARNING', $this->transport->events[1]['level']);
    }

    public function test_immediate_mode_writes_each_event(): void
    {
        $c = $this->client(['flush' => 'immediate']);
        $c->captureMessage('one');

        $this->assertCount(1, $this->transport->events);
    }

    public function test_event_has_the_api_shape(): void
    {
        $c = $this->client(['release' => 'v1.6.2', 'environment' => 'staging']);
        $c->scope()->setUser(125, 'agency-17');
        $c->scope()->setTag('flow', 'search');
        $id = $c->captureException(new \RuntimeException('boom'));
        $c->flush();

        $e = $this->transport->events[0];
        $this->assertSame($id, $e['event_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $e['event_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $e['created_at']);
        $this->assertSame('staging', $e['environment']);
        $this->assertSame('v1.6.2', $e['release_version']);
        $this->assertSame('125', $e['user_id']);
        $this->assertSame('agency-17', $e['tenant_id']);
        $this->assertSame(['flow' => 'search'], $e['tags']);
        $this->assertSame('EXCEPTION', $e['type']);
        $this->assertSame('ERROR', $e['level']);
        $this->assertSame('RuntimeException', $e['exception_class']);
        $this->assertArrayNotHasKey('http_method', $e, 'no request fields in CLI');
    }

    public function test_per_request_cap(): void
    {
        $c = $this->client(['max_events_per_request' => 3, 'max_same_error_per_request' => 100]);
        for ($i = 0; $i < 10; $i++) {
            $c->captureMessage("m$i");
        }
        $c->flush();

        $this->assertCount(3, $this->transport->events);
    }

    public function test_same_error_cap(): void
    {
        $c = $this->client(['max_same_error_per_request' => 2]);
        for ($i = 0; $i < 5; $i++) {
            $c->captureError(E_WARNING, 'Undefined variable $x', __FILE__, 10);
        }
        $c->captureError(E_WARNING, 'Undefined variable $y', __FILE__, 11);
        $c->flush();

        $this->assertCount(3, $this->transport->events);
    }

    public function test_reset_request_clears_caps_and_scope(): void
    {
        $c = $this->client(['max_events_per_request' => 1]);
        $c->scope()->setUser(1);
        $c->captureMessage('a');
        $c->resetRequest();
        $c->captureMessage('b');
        $c->flush();

        $this->assertCount(2, $this->transport->events);
        $this->assertArrayNotHasKey('user_id', $this->transport->events[1]);
    }

    public function test_sampling_by_type(): void
    {
        $c = $this->client(['sample_rates' => ['PHP_DEPRECATED' => 0.0, 'PHP_NOTICE' => 1.0]]);
        $c->captureError(E_DEPRECATED, 'old', __FILE__, 1);
        $c->captureError(E_NOTICE, 'notice', __FILE__, 2);
        $c->flush();

        $this->assertCount(1, $this->transport->events);
        $this->assertSame('PHP_NOTICE', $this->transport->events[0]['type']);
    }

    public function test_ignored_exception_classes(): void
    {
        $c = $this->client(['ignore_exceptions' => [\LogicException::class]]);
        $c->captureException(new \InvalidArgumentException('child of LogicException'));
        $c->captureException(new \RuntimeException('kept'));
        $c->flush();

        $this->assertCount(1, $this->transport->events);
    }

    public function test_before_send_can_change_or_drop(): void
    {
        $c = $this->client(['before_send' => function (array $e) {
            if ($e['message'] === 'drop me') {
                return null;
            }
            $e['tags'] = ['seen' => 'yes'];

            return $e;
        }]);
        $c->captureMessage('drop me');
        $c->captureMessage('keep me');
        $c->flush();

        $this->assertCount(1, $this->transport->events);
        $this->assertSame(['seen' => 'yes'], $this->transport->events[0]['tags']);
    }

    public function test_masking_runs_but_never_touches_ids(): void
    {
        $c = $this->client();
        $c->scope()->setContext('db', ['password' => 'secret', 'host' => 'db1']);
        $c->captureMessage('card 4111111111111111 rejected');
        $c->flush();

        $e = $this->transport->events[0];
        $this->assertSame('card [REDACTED] rejected', $e['message']);
        $this->assertSame('[REDACTED]', $e['context']['db']['password']);
        $this->assertSame('db1', $e['context']['db']['host']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $e['event_id']);
    }

    public function test_error_exception_keeps_its_severity(): void
    {
        $c = $this->client();
        $c->captureException(new \ErrorException('Undefined array key "x"', 0, E_WARNING, __FILE__, 5));
        $c->flush();

        $this->assertSame('PHP_WARNING', $this->transport->events[0]['type']);
        $this->assertSame('WARNING', $this->transport->events[0]['level']);
        $this->assertSame('ErrorException', $this->transport->events[0]['exception_class']);
    }

    public function test_a_throwing_transport_never_breaks_the_app(): void
    {
        $c = new Client(new Config(['project' => 'test', 'flush' => 'immediate']), new class() implements \Watchman\Transport\TransportInterface {
            public function send(string $project, array $events): bool
            {
                throw new \RuntimeException('disk full');
            }
        });

        $this->assertNotNull($c->captureMessage('still fine'));
    }

    public function test_previous_exceptions_and_breadcrumbs_are_attached(): void
    {
        $c = $this->client(['max_breadcrumbs' => 2]);
        $c->scope()->addBreadcrumb('sql', 'select 1');
        $c->scope()->addBreadcrumb('sql', 'select 2');
        $c->scope()->addBreadcrumb('sql', 'select 3');
        $c->captureException(new \RuntimeException('outer', 0, new \LogicException('inner')));
        $c->flush();

        $ctx = $this->transport->events[0]['context'];
        $this->assertSame('LogicException', $ctx['previous'][0]['class']);
        $this->assertSame(['select 2', 'select 3'], array_column($ctx['breadcrumbs'], 'message'));
    }

    public function test_facade_is_a_no_op_before_init(): void
    {
        Watchman::reset();

        $this->assertNull(Watchman::captureMessage('nothing'));
        Watchman::setUser(1);
        Watchman::setTag('a', 'b');
        Watchman::sql('select 1', 1.0, 'boom');
        Watchman::flush();
        $this->assertFalse(Watchman::isEnabled());
    }

    public function test_supplier_helper_tags_only_its_own_event(): void
    {
        $transport = new MemoryTransport();
        Watchman::init(['project' => 'itt', 'flush' => 'immediate', 'register_handlers' => false, 'kill_switch_file' => ''], $transport);

        Watchman::supplier('Travelport', 'AirSearch', new \RuntimeException('timeout after 30000ms'), ['duration_ms' => 30012]);
        Watchman::captureMessage('unrelated');

        $this->assertSame('SUPPLIER_ERROR', $transport->events[0]['type']);
        $this->assertSame(['supplier' => 'Travelport', 'supplier_operation' => 'AirSearch'], $transport->events[0]['tags']);
        $this->assertSame(30012, $transport->events[0]['context']['duration_ms']);
        $this->assertArrayNotHasKey('tags', $transport->events[1]);
    }

    public function test_sql_helper_breadcrumb_and_db_error(): void
    {
        $transport = new MemoryTransport();
        Watchman::init(['project' => 'itt', 'flush' => 'immediate', 'register_handlers' => false, 'kill_switch_file' => ''], $transport);

        Watchman::sql("SELECT * FROM users WHERE password = 'x'", 3.21);
        Watchman::sql('INSERT INTO booking ...', 12.0, 'Deadlock found when trying to get lock');

        $this->assertCount(1, $transport->events);
        $e = $transport->events[0];
        $this->assertSame('DB_ERROR', $e['type']);
        $this->assertSame('INSERT INTO booking ...', $e['context']['sql']);
        $this->assertSame("SELECT * FROM users WHERE password = [REDACTED]", $e['context']['breadcrumbs'][0]['message']);
        $this->assertSame('tests/Unit/ClientTest.php', substr($e['file'], -25));
    }

    public function test_message_capture_points_at_the_calling_line(): void
    {
        $transport = new MemoryTransport();
        Watchman::init(['project' => 'itt', 'flush' => 'immediate', 'register_handlers' => false, 'kill_switch_file' => ''], $transport);

        $line = __LINE__ + 1;
        Watchman::captureMessage('here');

        $this->assertSame($line, $transport->events[0]['line']);
        $this->assertSame(__FUNCTION__, substr((string) $transport->events[0]['stack_frames'][0]['function'], -strlen(__FUNCTION__)));
    }
}
