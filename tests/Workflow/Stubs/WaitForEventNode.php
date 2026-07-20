<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class WaitForEventNode extends Node
{
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $state->set('wait_for_event_node_executed', true);

        $request = $this->awaitEvent('user.signup');

        // Reached only on resume. awaitEvent()'s declared return type guarantees
        // $request is a WaitForEventRequest — a cross-TYPE resume (e.g. a
        // SleepUntilRequest) is rejected with a TypeError at the verb boundary
        // before this line. The engine itself stays opaque to resume type.
        $state->set('received_payload', $request?->getPayload());

        return new SecondEvent('Continued after event');
    }
}
