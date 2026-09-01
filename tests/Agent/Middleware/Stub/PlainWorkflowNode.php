<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware\Stub;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class PlainWorkflowNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StartEvent
    {
        return $event;
    }
}
