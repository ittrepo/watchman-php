<?php

namespace Watchman\Shipper;

interface HttpSender
{
    /**
     * POST a JSON body. Never throws: network failures come back as status 0.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string, error: string|null}
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array;
}
