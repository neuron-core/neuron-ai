<?php

declare(strict_types=1);

namespace Flowline;

/**
 * Passed to every task handler. Provides the triggering event,
 * step tooling for durable operations, and execution metadata.
 */
final class Context
{
    /**
     * @param Event $event The primary triggering event
     * @param Step $step Durable step operations
     * @param string $runId Unique run identifier
     * @param int $attempt Attempt number (0-indexed)
     * @param list<Event> $events All events in the batch
     */
    public function __construct(
        public readonly Event $event,
        public readonly Step $step,
        public readonly string $runId,
        public readonly int $attempt,
        public readonly array $events = [],
    ) {}
}
