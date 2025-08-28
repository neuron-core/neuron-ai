<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class NodeOne extends Node
{
    public function run(Event $event, WorkflowState $state): FirstEvent
    {
        $state->set('node_one_executed', true);

        $state->set('start_message', $event->message);

        return new FirstEvent('First complete');
    }
}
