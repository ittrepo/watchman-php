<?php

namespace Watchman;

/**
 * Builds stack frames (throw site first) and source code context.
 *
 * Frames carry file, line, function and in_app only. Arguments are never
 * captured: they are the main source of leaked passwords and huge payloads.
 */
final class Stacktrace
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param list<array<string, mixed>> $trace  debug_backtrace()/getTrace() shape
     * @return list<array<string, mixed>>
     */
    public function frames(array $trace, ?string $file, ?int $line): array
    {
        $max = (int) $this->config->get('max_frames');
        $frames = [];

        // PHP traces list calls; the throw/error site is $file:$line and the
        // function it happened in is trace[0]. Shift by one so each frame says
        // "this line, inside this function".
        foreach ($trace as $call) {
            $frames[] = $this->frame($file, $line, self::functionName($call));
            $file = isset($call['file']) ? (string) $call['file'] : null;
            $line = isset($call['line']) ? (int) $call['line'] : null;

            if (count($frames) >= $max) {
                return $frames;
            }
        }

        $frames[] = $this->frame($file, $line, null);

        return $frames;
    }

    /**
     * ±N lines around $line, or null when the file is unreadable or too big.
     *
     * @return array{start_line: int, error_line: int, lines: list<string>}|null
     */
    public function codeContext(?string $file, ?int $line): ?array
    {
        $around = (int) $this->config->get('code_context_lines');

        if ($around <= 0 || $file === null || $line === null || $line < 1 || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $size = @filesize($file);
        if ($size === false || $size > (int) $this->config->get('max_source_file_bytes')) {
            return null;
        }

        $start = max(1, $line - $around);
        $end = $line + $around;
        $lines = [];

        try {
            $handle = new \SplFileObject($file, 'r');
            $handle->seek($start - 1);
            for ($n = $start; $n <= $end && ! $handle->eof(); $n++) {
                $lines[] = substr(rtrim((string) $handle->current(), "\r\n"), 0, 300);
                $handle->next();
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $lines === [] ? null : ['start_line' => $start, 'error_line' => $line, 'lines' => $lines];
    }

    public function isInApp(?string $file): bool
    {
        if ($file === null) {
            return false;
        }

        $file = str_replace('\\', '/', $file);
        $root = $this->config->get('app_root');

        if ($root !== null && strpos($file, $root) !== 0) {
            return false;
        }

        if (strpos($file, '/vendor/') !== false) {
            return false;
        }

        $relative = $root !== null ? substr($file, strlen($root)) : $file;
        foreach ((array) $this->config->get('in_app_exclude') as $prefix) {
            if (is_string($prefix) && $prefix !== '' && strpos($relative, ltrim($prefix, '/')) === 0) {
                return false;
            }
        }

        return true;
    }

    /** Path relative to app_root, so the same code groups identically on every server. */
    public function relative(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $file = str_replace('\\', '/', $file);
        $root = $this->config->get('app_root');

        return $root !== null && strpos($file, $root) === 0 ? substr($file, strlen($root)) : $file;
    }

    /** @return array<string, mixed> */
    private function frame(?string $file, ?int $line, ?string $function): array
    {
        return [
            'file' => $file === null ? '[internal]' : $this->relative($file),
            'line' => $line,
            'function' => $function,
            'in_app' => $this->isInApp($file),
        ];
    }

    /** @param array<string, mixed> $call */
    private static function functionName(array $call): ?string
    {
        if (! isset($call['function'])) {
            return null;
        }

        return isset($call['class'])
            ? $call['class'].($call['type'] ?? '::').$call['function']
            : (string) $call['function'];
    }
}
