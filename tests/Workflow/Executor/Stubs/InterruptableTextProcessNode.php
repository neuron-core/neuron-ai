<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class InterruptableTextProcessNode extends Node
{
    public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('text_node_executed', true);

        $this->interrupt(
            new ApprovalRequest(
                'text branch needs approval',
                [new Action('approve_text', 'Approve', 'Approve text processing')],
            )
        );

        $state->set('text_node_resumed', true);

        return new StopEvent(result: 'TEXT_APPROVED');
    }
}
