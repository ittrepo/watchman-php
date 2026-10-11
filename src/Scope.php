<?php

namespace Watchman;

/**
 * Per-request state attached to every event: user, tenant, tags, extra
 * context and a ring buffer of breadcrumbs (what happened just before).
 */
final class Scope
{
    /** @var string|null */
    private $userId;

    /** @var string|null */
    private $tenantId;

    /** @var array<string, string> */
    private $tags = [];

    /** @var array<string, mixed> */
    private $context = [];

    /** @var list<array<string, mixed>> */
    private $breadcrumbs = [];

    /** @var int */
    private $maxBreadcrumbs;

    /** @var string|null */
    private $requestId;

    public function __construct(int $maxBreadcrumbs = 30)
    {
        $this->maxBreadcrumbs = max(0, $maxBreadcrumbs);
    }

    /** @param int|string|null $id */
    public function setUser($id, $tenantId = null): void
    {
        $this->userId = $id === null || $id === '' ? null : substr((string) $id, 0, 64);
        $this->tenantId = $tenantId === null || $tenantId === '' ? null : substr((string) $tenantId, 0, 64);
    }

    /** @param scalar|null $value */
    public function setTag(string $key, $value): void
    {
        if ($value === null) {
            unset($this->tags[$key]);

            return;
        }
        $this->tags[substr($key, 0, 64)] = substr((string) $value, 0, 200);
    }

    /** @param mixed $value */
    public function setContext(string $key, $value): void
    {
        $this->context[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function addBreadcrumb(string $category, string $message, array $data = [], string $level = 'INFO'): void
    {
        if ($this->maxBreadcrumbs === 0) {
            return;
        }

        $this->breadcrumbs[] = [
            't' => gmdate('H:i:s').sprintf('.%03d', (int) (fmod(microtime(true), 1) * 1000)),
            'category' => $category,
            'level' => $level,
            'message' => substr($message, 0, 1000),
            'data' => $data,
        ];

        if (count($this->breadcrumbs) > $this->maxBreadcrumbs) {
            array_shift($this->breadcrumbs);
        }
    }

    public function setRequestId(?string $id): void
    {
        $this->requestId = $id === null ? null : substr($id, 0, 64);
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    /** @return array<string, string> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /** @return list<array<string, mixed>> */
    public function breadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    /** Start of a new request in long-running processes (queue workers, Octane). */
    public function clear(): void
    {
        $this->userId = null;
        $this->tenantId = null;
        $this->tags = [];
        $this->context = [];
        $this->breadcrumbs = [];
        $this->requestId = null;
    }
}
