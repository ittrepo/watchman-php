<?php

namespace Watchman;

use Watchman\Support\ErrorLevels;
use Watchman\Support\Masker;
use Watchman\Transport\TransportInterface;

/**
 * Captures, guards and buffers events, and owns the PHP handlers.
 *
 * Rules every public method follows:
 *   - never throws, never changes what PHP would have done without the SDK;
 *   - bounded work per request (event caps, frame caps, source size cap);
 *   - no network: events go to the transport (the spool) only.
 */
final class Client
{
    /** Fields that must never be touched by masking (they look like card numbers sometimes). */
    private const UNMASKED = ['event_id', 'created_at', 'request_id', 'release_version', 'line'];

    /** @var Config */
    private $config;

    /** @var TransportInterface */
    private $transport;

    /** @var Scope */
    private $scope;

    /** @var EventFactory */
    private $factory;

    /** @var Masker */
    private $masker;

    /** @var list<array<string, mixed>> */
    private $buffer = [];

    /** @var int */
    private $captured = 0;

    /** @var array<string, int> */
    private $seen = [];

    /** @var bool */
    private $capturing = false;

    /** @var callable|null */
    private $previousErrorHandler;

    /** @var callable|null */
    private $previousExceptionHandler;

    /** @var bool */
    private $shutdownRegistered = false;

    /** @var string|null Freed on shutdown so an out-of-memory fatal can still be recorded. */
    private $reservedMemory;

    /** @var \Throwable|null */
    private $uncaught;

    public function __construct(Config $config, TransportInterface $transport)
    {
        $this->config = $config;
        $this->transport = $transport;
        $this->scope = new Scope((int) $config->get('max_breadcrumbs'));
        $this->factory = new EventFactory($config, $this->scope, new Stacktrace($config));
        $this->masker = new Masker((array) $config->get('mask_keys'), (array) $config->get('mask_patterns'));
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function config(): Config
    {
        return $this->config;
    }

    // ------------------------------------------------------------------ capture

    /** @param array<string, mixed> $context */
    public function captureException(\Throwable $e, array $context = [], ?string $type = null, ?string $level = null): ?string
    {
        foreach ((array) $this->config->get('ignore_exceptions') as $class) {
            if (is_string($class) && $e instanceof $class) {
                return null;
            }
        }

        $factory = $this->factory;

        return $this->guarded(function () use ($factory, $e, $context, $type, $level) {
            return $factory->fromThrowable($e, $context, $type, $level);
        });
    }

    /** @param array<string, mixed> $context */
    public function captureMessage(string $message, string $level = 'INFO', string $type = 'MESSAGE', array $context = []): ?string
    {
        $factory = $this->factory;
        $level = strtoupper($level);

        return $this->guarded(function () use ($factory, $message, $level, $type, $context) {
            return $factory->fromMessage($message, $level, $type, $context);
        });
    }

    /**
     * @param list<array<string, mixed>> $trace
     * @param array<string, mixed>       $context
     */
    public function captureError(int $errno, string $message, string $file, int $line, array $trace = [], array $context = []): ?string
    {
        $factory = $this->factory;

        return $this->guarded(function () use ($factory, $errno, $message, $file, $line, $trace, $context) {
            return $factory->fromError($errno, $message, $file, $line, $trace, $context);
        });
    }

    /**
     * Build with $build and keep the result if every guard lets it through.
     * Re-entrancy (an error raised while capturing) is dropped, not recursed.
     */
    private function guarded(callable $build): ?string
    {
        if ($this->capturing || $this->captured >= (int) $this->config->get('max_events_per_request')) {
            return null;
        }

        $this->capturing = true;

        try {
            $event = $build();

            return is_array($event) ? $this->accept($event) : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            $this->capturing = false;
        }
    }

    /** @param array<string, mixed> $event */
    private function accept(array $event): ?string
    {
        $type = (string) ($event['type'] ?? '');
        $rates = (array) $this->config->get('sample_rates');

        if (isset($rates[$type])) {
            $rate = (float) $rates[$type];
            if ($rate <= 0.0 || ($rate < 1.0 && mt_rand() / mt_getrandmax() > $rate)) {
                return null;
            }
        }

        // The same warning inside a loop is one problem, not a thousand events.
        $key = $type.'|'.($event['exception_class'] ?? '').'|'.($event['file'] ?? '').'|'.($event['line'] ?? '');
        $this->seen[$key] = ($this->seen[$key] ?? 0) + 1;
        if ($this->seen[$key] > (int) $this->config->get('max_same_error_per_request')) {
            return null;
        }

        $hook = $this->config->get('before_send');
        if (is_callable($hook)) {
            $event = $hook($event);
            if (! is_array($event)) {
                return null;
            }
        }

        $event = $this->mask($event);

        $this->captured++;
        $this->buffer[] = $event;

        if ($this->flushImmediately()) {
            $this->flush();
        }

        return isset($event['event_id']) ? (string) $event['event_id'] : null;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function mask(array $event): array
    {
        $keep = array_intersect_key($event, array_flip(self::UNMASKED));

        return array_replace($this->masker->maskArray($event), $keep);
    }

    private function flushImmediately(): bool
    {
        $mode = (string) $this->config->get('flush');

        return $mode === 'immediate' || ($mode === 'auto' && PHP_SAPI === 'cli');
    }

    /** Write buffered events to the transport. Safe to call any time. */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];

        try {
            $this->transport->send($this->config->project(), $events);
        } catch (\Throwable $e) {
            // Transports must not throw; if one does, losing the event is
            // better than breaking the request.
        }
    }

    /** For long-running processes: start a fresh "request". */
    public function resetRequest(): void
    {
        $this->flush();
        $this->captured = 0;
        $this->seen = [];
        $this->scope->clear();
    }

    // ----------------------------------------------------------------- handlers

    public function registerHandlers(): void
    {
        $this->registerShutdown();

        if (! $this->config->get('register_handlers')) {
            return;
        }

        $this->previousErrorHandler = set_error_handler([$this, 'handleError']);
        $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        $this->reservedMemory = str_repeat(' ', 32768);
    }

    /** Only the shutdown flush (frameworks with their own error reporting). */
    public function registerShutdown(): void
    {
        if (! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function([$this, 'handleShutdown']);
        }
    }

    /**
     * PHP error handler. Records the error, then hands over exactly as PHP would:
     * to the previous handler if there was one, otherwise back to PHP's own
     * handling (return false), so logging/display stay unchanged.
     */
    public function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        // error_reporting() is 0 (PHP 7) or the fatal-only mask (PHP 8) under @.
        if ((error_reporting() & $errno) && ((int) $this->config->get('capture_errors') & $errno)) {
            $this->captureError($errno, $errstr, $errfile, $errline, EventFactory::callerTrace());
        }

        if ($this->previousErrorHandler !== null) {
            return (bool) call_user_func($this->previousErrorHandler, $errno, $errstr, $errfile, $errline);
        }

        return false;
    }

    /**
     * Uncaught exception: record it, then let it continue to whatever would
     * have handled it without the SDK.
     */
    public function handleException(\Throwable $e): void
    {
        $this->uncaught = $e;
        $this->captureException($e, ['handled' => false], null, 'CRITICAL');
        $this->flush();

        if ($this->previousExceptionHandler !== null) {
            call_user_func($this->previousExceptionHandler, $e);

            return;
        }

        // No previous handler: rethrow. An exception thrown from inside the
        // exception handler is not given back to the handler; PHP treats it as
        // uncaught, prints/logs "Uncaught ..." and exits 255, exactly as it
        // would without the SDK. (Do not call restore_exception_handler() here:
        // inside the handler that re-arms this handler and loops.)
        throw $e;
    }

    public function handleShutdown(): void
    {
        $this->reservedMemory = null;

        try {
            $error = error_get_last();

            if (is_array($error) && in_array((int) $error['type'], ErrorLevels::FATAL_TYPES, true)
                && ! $this->alreadyRecordedUncaught((string) $error['message'])) {
                $this->captureError(
                    (int) $error['type'],
                    (string) $error['message'],
                    (string) $error['file'],
                    (int) $error['line'],
                    [],
                    ['handled' => false]
                );
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $this->flush();

        // Shutdown functions registered after ours (and destructors) may still
        // capture; flush once more at the very end.
        register_shutdown_function([$this, 'flush']);
    }

    private function alreadyRecordedUncaught(string $message): bool
    {
        return $this->uncaught !== null && strpos($message, 'Uncaught ') === 0;
    }
}
