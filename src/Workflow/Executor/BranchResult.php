<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;

/**
 * Encapsulates the result of a parallel branch execution.
 */
class BranchResult
{
    /**
     * @param array<string, mixed> $stateChanges Changes made to state during branch execution
     * @param array<int, Event> $streamedEvents Events yielded during branch execution
     */
    public function __construct(
        public readonly string $branchId,
        public readonly array $stateChanges = [],
        public readonly array $streamedEvents = []
    ) {
    }
}
