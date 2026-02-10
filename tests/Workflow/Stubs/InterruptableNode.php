<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class InterruptableNode extends Node
{
    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $state->set('interruptable_node_executed', true);

        $this->interrupt(
            new ApprovalRequest(
                'human input needed',
                [new Action('action_id', 'action_name', 'action_description')],
            )
        );

        $state->set('received_feedback', 'completed');

        return new SecondEvent('Continued after interrupt');
    }
}
