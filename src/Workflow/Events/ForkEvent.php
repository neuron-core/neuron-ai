<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

/**
 * Emitted when parallel branches are spawned.
 */
class ForkEvent implements Event
{
    /**
     * @param array<string, Event> $branchEvents Map of branch ID to starting event
     */
    public function __construct(
        public readonly array $branchEvents
    ) {
    }
}
