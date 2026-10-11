<?php

namespace Watchman\Transport;

/** Keeps events in memory. For tests and for inspecting what would be sent. */
final class MemoryTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public $events = [];

    /** @var int */
    public $sends = 0;

    public function send(string $project, array $events): bool
    {
        $this->sends++;
        foreach ($events as $event) {
            $event['_project'] = $project;
            $this->events[] = $event;
        }

        return true;
    }
}
