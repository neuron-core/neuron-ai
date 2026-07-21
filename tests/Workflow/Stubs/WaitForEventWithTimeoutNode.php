<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use DateTimeImmutable;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Like WaitForEventNode, but bounds the wait with a deadline and shows the
 * developer-facing timeout branch: awaitEvent() returns null when the deadline
 * elapses, so the node branches on null (no isExpired() call, no flag inspection).
 */
class WaitForEventWithTimeoutNode extends Node
{
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $request = $this->awaitEvent('user.signup', expiresAt: new DateTimeImmutable('+1 hour'));

        if ($request === null) {
            // Timeout branch: the deadline elapsed with no event delivered.
            $state->set('timed_out', true);
            return new SecondEvent('timed out');
        }

        $state->set('received_payload', $request->getPayload());
        return new SecondEvent('got event');
    }
}
