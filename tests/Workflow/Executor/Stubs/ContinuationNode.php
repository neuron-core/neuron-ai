<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ContinuationNode extends Node
{
    public function __invoke(ContinuationEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('continuation_node_executed', true);
        return new StopEvent(result: 'final_result');
    }
}
