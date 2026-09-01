<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use DateTimeImmutable;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Like WaitForEventNode, but bounds the wait with a deadline and shows the
 * developer-facing timeout branch: awaitEvent() returns null when the deadline
 * elapses (delivered via the $timedOut resume flag), so the node branches on null.
 */
class WaitForEventWithTimeoutNode extends Node
{
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $payload = $this->awaitEvent('user.signup', expiresAt: new DateTimeImmutable('+1 hour'));

        if ($payload === null) {
            // Timeout branch: the deadline elapsed with no event delivered.
            $state->set('timed_out', true);
            return new SecondEvent('timed out');
        }

        $state->set('received_payload', $payload);
        return new SecondEvent('got event');
    }
}
