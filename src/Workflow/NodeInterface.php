<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

interface NodeInterface
{
    public function run(Event $event, WorkflowState $state): \Generator|Event;

    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        bool $isResuming = false,
        array $feedback = []
    ): void;
}
