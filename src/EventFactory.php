<?php

namespace Watchman;

use Watchman\Support\ErrorLevels;
use Watchman\Support\Uuid;

/**
 * Turns exceptions, PHP errors and messages into the event shape the
 * Watchman ingest API (POST /api/v1/errors/batch) accepts.
 */
final class EventFactory
{
    /** @var Config */
    private $config;

    /** @var Scope */
    private $scope;

    /** @var Stacktrace */
    private $stacktrace;

    public function __construct(Config $config, Scope $scope, Stacktrace $stacktrace)
    {
        $this->config = $config;
        $this->scope = $scope;
        $this->stacktrace = $stacktrace;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function fromThrowable(\Throwable $e, array $context = [], ?string $type = null, ?string $level = null): array
    {
        $defaultType = 'EXCEPTION';
        $defaultLevel = 'ERROR';

        // Frameworks (Laravel) turn warnings into ErrorException: keep the
        // real severity instead of reporting every notice as an ERROR.
        if ($e instanceof \ErrorException) {
            list($defaultType, $defaultLevel) = ErrorLevels::forErrno($e->getSeverity());
        }

        $frames = $this->stacktrace->frames($e->getTrace(), $e->getFile(), $e->getLine());

        $previous = [];
        $p = $e->getPrevious();
        while ($p !== null && count($previous) < 5) {
            $previous[] = [
                'class' => get_class($p),
                'message' => $this->truncate($p->getMessage()),
                'file' => $this->stacktrace->relative($p->getFile()),
                'line' => $p->getLine(),
            ];
            $p = $p->getPrevious();
        }
        if ($previous !== []) {
            $context['previous'] = $previous;
        }

        if ($e->getCode() !== 0) {
            $context['code'] = $e->getCode();
        }

        return $this->build([
            'type' => $type ?? $defaultType,
            'level' => $level ?? $defaultLevel,
            'message' => $this->truncate($e->getMessage() === '' ? get_class($e) : $e->getMessage()),
            'exception_class' => get_class($e),
            'file' => $this->stacktrace->relative($e->getFile()),
            'line' => $e->getLine(),
            'stack_frames' => $frames,
            'code_context' => $this->contextFor($frames, $e->getFile(), $e->getLine()),
        ], $context);
    }

    /**
     * @param list<array<string, mixed>> $trace
     * @param array<string, mixed>       $context
     * @return array<string, mixed>
     */
    public function fromError(int $errno, string $message, string $file, int $line, array $trace, array $context = []): array
    {
        list($type, $level) = ErrorLevels::forErrno($errno);
        $frames = $this->stacktrace->frames($trace, $file, $line);

        return $this->build([
            'type' => $type,
            'level' => $level,
            'message' => $this->truncate($message),
            'exception_class' => null,
            'file' => $this->stacktrace->relative($file),
            'line' => $line,
            'stack_frames' => $frames,
            'code_context' => $this->contextFor($frames, $file, $line),
        ], $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function fromMessage(string $message, string $level, string $type, array $context = [], bool $withTrace = true): array
    {
        $frames = [];
        $file = null;
        $line = null;

        if ($withTrace) {
            list($site, $trace) = self::callSite();
            $file = isset($site['file']) ? (string) $site['file'] : null;
            $line = isset($site['line']) ? (int) $site['line'] : null;
            $frames = $this->stacktrace->frames($trace, $file, $line);
        }

        return $this->build([
            'type' => $type,
            'level' => $level,
            'message' => $this->truncate($message),
            'exception_class' => null,
            'file' => $this->stacktrace->relative($file),
            'line' => $line,
            'stack_frames' => $frames,
            'code_context' => $frames === [] ? null : $this->contextFor($frames, $file, $line),
        ], $context);
    }

    /**
     * debug_backtrace() without the SDK's own frames, for PHP error handlers:
     * the handler is invoked by the engine, so everything after the SDK frames
     * is exactly the application's call stack.
     *
     * @return list<array<string, mixed>>
     */
    public static function callerTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        while ($trace !== [] && self::isSdkFrame($trace[0])) {
            array_shift($trace);
        }

        return array_values($trace);
    }

    /**
     * Where application code called into the SDK (e.g. Watchman::captureMessage()
     * on app.php:10), plus the application stack above that point.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public static function callSite(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $last = null;

        foreach ($trace as $i => $call) {
            if (self::isSdkFrame($call)) {
                $last = $i;
            }
        }

        if ($last === null) {
            return [[], $trace];
        }

        // trace[$last] is the call INTO the SDK: its file/line is the app's line.
        return [$trace[$last], array_values(array_slice($trace, $last + 1))];
    }

    /** @param array<string, mixed> $call */
    private static function isSdkFrame(array $call): bool
    {
        return isset($call['class']) && strpos((string) $call['class'], 'Watchman\\') === 0
            && strpos((string) $call['class'], 'Watchman\\Tests\\') !== 0;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build(array $fields, array $context): array
    {
        $request = $this->requestFields();

        $mergedContext = array_merge($this->scope->context(), $context);
        $breadcrumbs = $this->scope->breadcrumbs();
        if ($breadcrumbs !== []) {
            $mergedContext['breadcrumbs'] = $breadcrumbs;
        }

        $event = array_merge([
            'event_id' => Uuid::v4(),
            'environment' => substr((string) $this->config->get('environment'), 0, 32),
            'release_version' => $this->config->get('release') === null ? null : substr((string) $this->config->get('release'), 0, 64),
            'created_at' => self::now(),
            'user_id' => $this->scope->userId(),
            'tenant_id' => $this->scope->tenantId(),
            'tags' => $this->scope->tags() === [] ? null : $this->scope->tags(),
            'context' => $mergedContext === [] ? null : $mergedContext,
        ], $request, $fields);

        // The API rejects unknown-but-null-safe fields fine; drop nulls to keep
        // the spool small.
        return array_filter($event, function ($v) {
            return $v !== null;
        });
    }

    /** @return array<string, mixed> */
    private function requestFields(): array
    {
        if (PHP_SAPI === 'cli' || ! isset($_SERVER['REQUEST_METHOD'])) {
            return [
                'request_id' => $this->scope->requestId(),
                'url' => isset($_SERVER['argv']) && is_array($_SERVER['argv'])
                    ? substr('cli: '.implode(' ', $_SERVER['argv']), 0, 2000)
                    : null,
            ];
        }

        if ($this->scope->requestId() === null) {
            $header = 'HTTP_'.strtoupper(str_replace('-', '_', (string) $this->config->get('request_id_header')));
            $incoming = isset($_SERVER[$header]) ? (string) $_SERVER[$header] : '';
            $this->scope->setRequestId(preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $incoming) ? $incoming : Uuid::v4());
        }

        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $https = ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        return [
            'request_id' => $this->scope->requestId(),
            'url' => substr(($host !== '' ? ($https ? 'https://' : 'http://').$host : '').$uri, 0, 2000),
            'http_method' => substr((string) $_SERVER['REQUEST_METHOD'], 0, 8),
            'ip' => $this->config->get('send_ip') ? $this->clientIp() : null,
        ];
    }

    private function clientIp(): ?string
    {
        if ($this->config->get('trust_forwarded_for') && ! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /**
     * Source lines around the first in-app frame (falls back to the error site).
     *
     * @param list<array<string, mixed>> $frames
     * @return array<string, mixed>|null
     */
    private function contextFor(array $frames, ?string $file, ?int $line): ?array
    {
        $root = $this->config->get('app_root');

        foreach ($frames as $frame) {
            if (! empty($frame['in_app']) && $frame['file'] !== '[internal]') {
                $absolute = $root !== null && strpos((string) $frame['file'], '/') !== 0
                    ? $root.$frame['file']
                    : (string) $frame['file'];

                return $this->stacktrace->codeContext($absolute, isset($frame['line']) ? (int) $frame['line'] : null);
            }
        }

        return $this->stacktrace->codeContext($file, $line);
    }

    private function truncate(string $message): string
    {
        $max = (int) $this->config->get('max_message_length');

        if (strlen($message) <= $max) {
            return $message;
        }

        return (function_exists('mb_strcut') ? mb_strcut($message, 0, $max, 'UTF-8') : substr($message, 0, $max)).'…[truncated]';
    }

    /** ISO-8601 UTC with microseconds: 2026-10-10T08:00:00.123456Z */
    public static function now(): string
    {
        $now = \DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)), new \DateTimeZone('UTC'));

        return $now === false ? gmdate('Y-m-d\TH:i:s\Z') : $now->format('Y-m-d\TH:i:s.u\Z');
    }
}
