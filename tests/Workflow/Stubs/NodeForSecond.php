<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\WorkflowState;

class NodeForSecond extends Node
{
    public function run(Event $event, WorkflowState $state): StopEvent
    {
        $state->set('second_path_executed', true);
        $state->set('final_second_message', $event->message);

        return new StopEvent('Second path complete');
    }
}
