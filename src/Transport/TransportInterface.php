<?php

namespace Watchman\Transport;

interface TransportInterface
{
    /**
     * Hand events over for delivery. Must never throw.
     *
     * @param list<array<string, mixed>> $events
     */
    public function send(string $project, array $events): bool;
}
