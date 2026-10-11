<?php

namespace Watchman;

use Watchman\Transport\SpoolTransport;
use Watchman\Transport\TransportInterface;

/**
 * Static entry point.
 *
 *   Watchman::init(['project' => 'itt-ota', 'app_root' => '/var/www/itt', 'release' => 'v1.6.2']);
 *
 * Every method is a silent no-op until init() succeeds, and after init() if
 * the kill switch file exists. Calling them from anywhere is always safe.
 */
final class Watchman
{
    public const VERSION = '0.1.0';

    /** @var Client|null */
    private static $client;

    /**
     * @param array<string, mixed> $options  see Config::defaults()
     */
    public static function init(array $options, ?TransportInterface $transport = null): ?Client
    {
        try {
            $config = new Config($options);

            $killSwitch = (string) $config->get('kill_switch_file');
            if (! $config->isUsable() || ($killSwitch !== '' && @file_exists($killSwitch))) {
                self::$client = null;

                return null;
            }

            self::$client = new Client($config, $transport ?? new SpoolTransport((string) $config->get('spool_dir')));
            self::$client->registerHandlers();

            return self::$client;
        } catch (\Throwable $e) {
            self::$client = null;

            return null;
        }
    }

    public static function client(): ?Client
    {
        return self::$client;
    }

    public static function isEnabled(): bool
    {
        return self::$client !== null;
    }

    /** @param array<string, mixed> $context */
    public static function captureException(\Throwable $e, array $context = []): ?string
    {
        return self::$client === null ? null : self::$client->captureException($e, $context);
    }

    /** @param array<string, mixed> $context */
    public static function captureMessage(string $message, string $level = 'INFO', array $context = []): ?string
    {
        return self::$client === null ? null : self::$client->captureMessage($message, $level, 'MESSAGE', $context);
    }

    /**
     * @param int|string|null $id
     * @param int|string|null $tenantId  ITT: agency / tenant id
     */
    public static function setUser($id, $tenantId = null): void
    {
        if (self::$client !== null) {
            self::$client->scope()->setUser($id, $tenantId);
        }
    }

    /** @param scalar|null $value */
    public static function setTag(string $key, $value): void
    {
        if (self::$client !== null) {
            self::$client->scope()->setTag($key, $value);
        }
    }

    /** @param array<string, scalar|null> $tags */
    public static function setTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            self::setTag((string) $key, $value);
        }
    }

    /** @param mixed $value */
    public static function setContext(string $key, $value): void
    {
        if (self::$client !== null) {
            self::$client->scope()->setContext($key, $value);
        }
    }

    /** @param array<string, mixed> $data */
    public static function addBreadcrumb(string $category, string $message, array $data = [], string $level = 'INFO'): void
    {
        if (self::$client !== null) {
            self::$client->scope()->addBreadcrumb($category, $message, $data, $level);
        }
    }

    /**
     * Record a supplier failure (Travelport, Sabre, ...). Accepts a message or
     * the caught exception. Grouped per supplier + operation via tags.
     *
     *   Watchman::supplier('Travelport', 'AirSearch', $e, ['duration_ms' => 30012]);
     *
     * @param string|\Throwable    $error
     * @param array<string, mixed> $context
     */
    public static function supplier(string $supplier, string $operation, $error, array $context = [], string $level = 'ERROR'): ?string
    {
        if (self::$client === null) {
            return null;
        }

        $scope = self::$client->scope();
        $previousTags = $scope->tags();
        $scope->setTag('supplier', $supplier);
        $scope->setTag('supplier_operation', $operation);

        try {
            $context = ['supplier' => $supplier, 'operation' => $operation] + $context;

            if ($error instanceof \Throwable) {
                return self::$client->captureException($error, $context, 'SUPPLIER_ERROR', strtoupper($level));
            }

            return self::$client->captureMessage((string) $error, $level, 'SUPPLIER_ERROR', $context);
        } finally {
            // Supplier tags belong to this event only, not the rest of the request.
            $scope->setTag('supplier', $previousTags['supplier'] ?? null);
            $scope->setTag('supplier_operation', $previousTags['supplier_operation'] ?? null);
        }
    }

    /**
     * Record a query as a breadcrumb; with $error, also report it as DB_ERROR.
     * Wire this into the app's DB wrapper.
     */
    public static function sql(string $sql, ?float $durationMs = null, ?string $error = null): ?string
    {
        if (self::$client === null) {
            return null;
        }

        $sql = substr(preg_replace('/\s+/', ' ', trim($sql)) ?? $sql, 0, 2000);
        $data = $durationMs === null ? [] : ['duration_ms' => round($durationMs, 2)];

        self::$client->scope()->addBreadcrumb('sql', $sql, $data, $error === null ? 'INFO' : 'ERROR');

        if ($error === null) {
            return null;
        }

        return self::$client->captureMessage($error, 'ERROR', 'DB_ERROR', ['sql' => $sql] + $data);
    }

    public static function flush(): void
    {
        if (self::$client !== null) {
            self::$client->flush();
        }
    }

    /** Long-running workers: call between jobs/requests. */
    public static function resetRequest(): void
    {
        if (self::$client !== null) {
            self::$client->resetRequest();
        }
    }

    /** Tests only: forget the client (handlers stay registered by PHP). */
    public static function reset(): void
    {
        self::$client = null;
    }
}
