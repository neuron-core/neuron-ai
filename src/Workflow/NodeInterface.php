<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Persistence\PersistenceInterface;

interface NodeInterface
{
    public function run(Event $event, WorkflowState $state): Event;

    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        bool $isResuming = false,
        array $feedback = []
    ): void;
}
