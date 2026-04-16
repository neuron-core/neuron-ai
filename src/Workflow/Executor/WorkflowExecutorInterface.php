<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;

interface WorkflowExecutorInterface
{
    /**
     * Execute the workflow starting from the given event and node.
     *
     * @return Generator<int, Event, mixed, void>
     */
    public function execute(
        WorkflowInterface $workflow,
        Event $currentEvent,
        NodeInterface $currentNode,
        ?InterruptRequest $resumeRequest = null
    ): Generator;

    /**
     * Resume the workflow from a persisted interrupt.
     *
     * Handles both linear and parallel interrupts internally,
     * routing to the appropriate execution path.
     *
     * @return Generator<int, Event, mixed, void>
     */
    public function resume(
        WorkflowInterface $workflow,
        WorkflowInterrupt $interrupt,
        InterruptRequest $resumeRequest
    ): Generator;
}
