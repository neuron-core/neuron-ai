<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class AlwaysInterruptTextProcessNode extends Node
{
    public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('always_interrupt_executed', true);

        $this->interrupt(
            new ApprovalRequest(
                'always needs approval',
                [new Action('a1', 'Approve', 'Approve')],
            )
        );

        $state->set('always_interrupt_resumed', true);

        return new StopEvent(result: 'DONE');
    }
}
