<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class InterruptableNode extends Node
{
    public function run(Event $event, WorkflowState $state): SecondEvent
    {
        $state->set('interruptable_node_executed', true);

        if (!$state->has('human_feedback')) {
            $this->interrupt(['message' => 'Need human input']);
        }

        $feedback = $state->get('human_feedback', 'default');
        $state->set('received_feedback', $feedback);

        return new SecondEvent('Continued after interrupt');
    }
}
