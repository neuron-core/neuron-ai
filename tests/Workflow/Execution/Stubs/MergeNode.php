<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Execution\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class MergeNode extends Node
{
    public function __invoke(MergeEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('merge_node_executed', true);
        return new StopEvent();
    }
}
