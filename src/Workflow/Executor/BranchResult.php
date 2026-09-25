<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

/**
 * One streamed item or the terminal outcome of an asynchronous branch.
 *
 * AsyncBranchRunner advances each branch only once per instance, keeping
 * output bounded while the parent forwards events with backpressure.
 */
class BranchResult
{
    /**
     * @param object|null $streamedEvent The next item streamed by the branch
     * @param int|null $streamedKey Its position in the segment's stream
     * @param bool $paused The branch stopped at a durable boundary.
     */
    public function __construct(
        public readonly ?object $streamedEvent = null,
        public readonly ?int $streamedKey = null,
        public readonly bool $paused = false,
    ) {
    }
}
