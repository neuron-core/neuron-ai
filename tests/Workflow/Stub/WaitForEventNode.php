<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class WaitForEventNode extends Node
{
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $state->set('wait_for_event_node_executed', true);

        // awaitEvent() returns the delivered event payload directly on resume.
        $payload = $this->awaitEvent('user.signup');

        // Reached only on resume.
        $state->set('received_payload', $payload);

        return new SecondEvent('Continued after event');
    }
}
