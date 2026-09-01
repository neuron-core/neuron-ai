<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class SecondNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('second', $event->value);
        return new StopEvent();
    }
}
