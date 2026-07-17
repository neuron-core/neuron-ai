<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;

/**
 * Encapsulates the result of a parallel branch execution.
 */
class BranchResult
{
    /**
     * @param array<int, Event> $streamedEvents Events yielded during branch execution
     * @param InterruptEvent|null $interrupt Set when the branch paused for input;
     *   null on normal completion (the branch reached a StopEvent).
     */
    public function __construct(
        public readonly string $branchId,
        public readonly mixed $result = null,
        public readonly array $streamedEvents = [],
        public readonly ?InterruptEvent $interrupt = null,
    ) {
    }
}
