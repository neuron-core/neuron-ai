<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class SummaryProcessNode extends Node
{
    public function __invoke(SummaryProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('summary_node_executed', true);
        return new StopEvent(result: 'SUMMARY');
    }
}
