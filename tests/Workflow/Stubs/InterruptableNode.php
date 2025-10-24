<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowInterrupt;
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

        $feedback = $this->interrupt(
            new InterruptRequest(
                [new Action('action_id', 'action_name', 'action_description')],
                'human input needed',
            )
        );

        $state->set('received_feedback', $feedback->getReason());

        return new SecondEvent('Continued after interrupt');
    }
}
