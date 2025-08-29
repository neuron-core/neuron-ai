<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Event;
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

        $feedback = $this->interrupt(['message' => 'Need human input']);

        $state->set('received_feedback', $feedback);

        return new SecondEvent('Continued after interrupt');
    }
}
