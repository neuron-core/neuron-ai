<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class DoubleInterruptTextProcessNode extends Node
{
    public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('double_step1', true);

        $this->interrupt(
            new ApprovalRequest(
                'first approval needed',
                [new Action('a1', 'Approve', 'First approval')],
            )
        );

        $state->set('double_step1_resumed', true);
        $state->set('double_step2', true);

        $this->interrupt(
            new ApprovalRequest(
                'second approval needed',
                [new Action('a2', 'Approve', 'Second approval')],
            )
        );

        $state->set('double_step2_resumed', true);

        return new StopEvent(result: 'DOUBLE_APPROVED');
    }
}
