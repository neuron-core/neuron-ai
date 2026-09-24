<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Executor\StepMemoizer;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The execution context the executor hands to a node before running it.
 *
 * Bundles what one execution needs besides the event, the state and the
 * resources, which the node receives as arguments: the inbound resume payload
 * (null when not resuming), the timeout flag, the durable memoizer bound to
 * the current step, the workflow's event dispatcher and the parallel branch
 * the step runs in. A node running in isolation (e.g. in a unit test) needs
 * none of them.
 */
class NodeContext
{
    public readonly bool $resuming;

    /**
     * @param array<string, mixed>|null $payload The inbound resume payload, or null when not resuming.
     * @param bool $timedOut True when the resume was produced by a deadline elapsing.
     */
    public function __construct(
        public readonly ?array $payload = null,
        public readonly bool $timedOut = false,
        public readonly ?StepMemoizer $memoizer = null,
        public readonly ?EventDispatcherInterface $dispatcher = null,
        ?bool $resuming = null,
        public readonly ?string $branchId = null,
    ) {
        $this->resuming = $resuming ?? ($this->payload !== null || $this->timedOut);
    }
}
