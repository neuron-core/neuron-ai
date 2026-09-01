<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class FirstNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): ProcessEvent
    {
        $state->set('first', 'executed');
        return new ProcessEvent('data');
    }
}
