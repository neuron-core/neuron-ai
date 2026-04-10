<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
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
}
