<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\WorkflowState;

class DurableNodeA extends CountableNode
{
    public function __invoke(StartEvent $event, WorkflowState $state): DurableEventA
    {
        $this->recordExecution();
        $state->set('step_a_executed', true);
        return new DurableEventA();
    }
}
