<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;
use Throwable;

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
        public readonly ?Event $finalEvent = null,
        public readonly array $stateChanges = [],
        public readonly array $streamedEvents = [],
        public readonly ?Throwable $error = null
    ) {
    }

    public function hasError(): bool
    {
        return $this->error instanceof Throwable;
    }

    public function isSuccessful(): bool
    {
        return !$this->error instanceof Throwable && $this->finalEvent instanceof Event;
    }
}
