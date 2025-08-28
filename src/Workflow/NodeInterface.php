<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

interface NodeInterface
{
    public function run(Event $event, WorkflowState $state): Event;

    public function setContext(WorkflowContext $context): void;
}
