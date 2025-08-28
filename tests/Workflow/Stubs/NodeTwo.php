<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\WorkflowState;

class NodeTwo extends Node
{
    /**
     * @param FirstEvent $event
     */
    public function run(Event $event, WorkflowState $state): SecondEvent|StopEvent
    {
        $state->set('node_two_executed', true);
        $state->set('first_message', $event->message);

        if ($state->get('interruptable_node_executed', false)) {
            return new StopEvent('Workflow complete');
        }

        return new SecondEvent('Second complete');
    }
}
