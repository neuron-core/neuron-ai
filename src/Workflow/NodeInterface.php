<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Events\Event;
use Generator;

interface NodeInterface
{
    public function run(Event $event, WorkflowState $state): Generator|Event;

    /**
     * Receive the execution context for the upcoming run. Called by the
     * executor before every node execution.
     */
    public function setWorkflowContext(NodeContext $context): void;
}
