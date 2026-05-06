<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

interface NodeRunner
{
    /**
     * Execute a single node with its full lifecycle.
     *
     * Yields streamed events from the node in real time (AI chunks, tool progress, etc.).
     * Returns the final Event that determines the next graph step.
     *
     * @param NodeInterface $node The node to execute
     * @param Event $event The event triggering this node
     * @param WorkflowState $state The current workflow state
     * @param object[] $middleware Middleware instances for this node
     * @param string|null $branchId Branch identifier (null for main flow)
     * @param InterruptRequest|null $resumeRequest The user's decision when resuming from interrupt
     *
     * @return Generator<int, Event, mixed, Event>
     */
    public function run(
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        array $middleware = [],
        ?string $branchId = null,
        ?InterruptRequest $resumeRequest = null,
    ): Generator;
}
