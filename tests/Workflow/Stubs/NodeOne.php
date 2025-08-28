<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\WorkflowState;

class NodeOne extends Node
{
    /**
     * @param StartEvent $event
     */
    public function run(Event $event, WorkflowState $state): FirstEvent
    {
        $state->set('node_one_executed', true);

        return new FirstEvent('First complete');
    }
}
