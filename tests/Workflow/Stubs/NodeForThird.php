<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\WorkflowState;

class NodeForThird extends Node
{
    public function __invoke(ThirdEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('third_path_executed', true);
        $state->set('final_third_message', $event->message);

        return new StopEvent('Third path complete');
    }
}
