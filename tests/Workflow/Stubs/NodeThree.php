<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowState;

class NodeThree extends Node
{
    public function __invoke(SecondEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('node_three_executed', true);
        $state->set('second_message', $event->message);

        return new StopEvent('Workflow complete');
    }
}
