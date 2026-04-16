<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class InterruptableStep2TextProcessNode extends Node
{
    public function __invoke(SecondTextProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('step2_executed', true);

        $this->interrupt(
            new ApprovalRequest(
                'step2 approval',
                [new Action('a2', 'Approve', 'Step 2 approval')],
            )
        );

        $state->set('step2_resumed', true);
        return new StopEvent(result: 'TWO_STEP_APPROVED');
    }
}
