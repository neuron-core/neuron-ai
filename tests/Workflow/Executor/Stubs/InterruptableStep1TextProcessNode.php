<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class InterruptableStep1TextProcessNode extends Node
{
    public function __invoke(TextProcessEvent $event, WorkflowState $state): SecondTextProcessEvent
    {
        $state->set('step1_executed', true);

        $this->interrupt(
            new ApprovalRequest(
                'step1 approval',
                [new Action('a1', 'Approve', 'Step 1 approval')],
            )
        );

        $state->set('step1_resumed', true);
        return new SecondTextProcessEvent();
    }
}
