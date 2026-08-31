<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\StepMemoizer;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The execution context the executor hands to a node before running it.
 *
 * Bundles everything a node needs for one execution: the current state and
 * event, the inbound resume payload (null when not resuming), the timeout
 * flag, the durable memoizer bound to the current step, and the workflow's
 * event dispatcher. A node running in isolation (e.g. in a unit test) needs
 * only the first two.
 */
class NodeContext
{
    public readonly bool $resuming;

    /**
     * @param array<string, mixed>|null $payload The inbound resume payload, or null when not resuming.
     * @param bool $timedOut True when the resume was produced by a deadline elapsing.
     */
    public function __construct(
        public readonly WorkflowState $state,
        public readonly Event $event,
        public readonly ?array $payload = null,
        public readonly bool $timedOut = false,
        public readonly ?StepMemoizer $memoizer = null,
        public readonly ?EventDispatcherInterface $dispatcher = null,
        ?bool $resuming = null,
    ) {
        $this->resuming = $resuming ?? ($this->payload !== null || $this->timedOut);
    }
}
