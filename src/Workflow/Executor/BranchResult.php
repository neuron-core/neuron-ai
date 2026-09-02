<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;

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
     * @param InterruptEvent|null $interrupt Set when the branch paused for input;
     *   null on normal completion (the branch reached a StopEvent).
     */
    public function __construct(
        public readonly mixed $result = null,
        public readonly ?Event $streamedEvent = null,
        public readonly ?InterruptEvent $interrupt = null,
    ) {
    }
}
