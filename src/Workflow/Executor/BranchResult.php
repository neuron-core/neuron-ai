<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;

/**
 * One streamed event or the terminal outcome of an asynchronous branch.
 *
 * AsyncExecutor advances each branch only once per instance, keeping output
 * bounded while the parent forwards events with backpressure.
 */
class BranchResult
{
    /**
     * @param Event|null $streamedEvent The next event yielded by the branch
     * @param bool $paused The branch stopped at a durable boundary.
     */
    public function __construct(
        public readonly mixed $result = null,
        public readonly ?Event $streamedEvent = null,
        public readonly bool $paused = false,
    ) {
    }
}
