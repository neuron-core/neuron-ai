<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowState;

class DurableNodeC extends CountableNode
{
    public function __invoke(DurableEventB $event, WorkflowState $state): StopEvent
    {
        $this->recordExecution();
        $state->set('step_c_executed', true);
        return new StopEvent(result: 'durable_complete');
    }
}
