<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowState;

class NodeTwo extends Node
{
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent|StopEvent|\Generator
    {
        $state->set('node_two_executed', true);
        $state->set('first_message', $event->message);
        $state->set('start_message', $event->message);

        yield new SecondEvent('Stream second event');

        if ($state->get('interruptable_node_executed', false)) {
            return new StopEvent('Workflow complete');
        }

        return new SecondEvent('Second complete');
    }
}
