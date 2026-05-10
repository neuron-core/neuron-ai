<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\WorkflowState;
use RuntimeException;

class DurableNodeB extends CountableNode
{
    public function __construct(protected bool $shouldCrash = false)
    {
    }

    public function __invoke(DurableEventA $event, WorkflowState $state): DurableEventB
    {
        $this->recordExecution();
        $state->set('step_b_executed', true);

        if ($this->shouldCrash) {
            throw new RuntimeException('Simulated crash in DurableNodeB');
        }

        return new DurableEventB();
    }
}
