<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class NodeTwo extends Node
{
    public function run(Event $event, WorkflowState $state): SecondEvent
    {
        $state->set('node_two_executed', true);
        $state->set('first_message', $event->message);

        return new SecondEvent('Second complete');
    }
}
