<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Suspends with an {@see ObjectCarryingRequest} (carrying a non-serializable closure)
 * to prove the request is fire-and-forget: it never reaches the serializer across a
 * FilePersistence-backed pause/resume.
 */
class ObjectCarryingInterruptNode extends Node
{
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        if ($this->isResuming()) {
            $state->set('resumed_with_object', true);
            return new SecondEvent('resumed');
        }

        $this->interrupt(new ObjectCarryingRequest('custom.event', fn (): string => 'never serialized'));

        $state->set('post_interrupt', true);
        return new SecondEvent('done');
    }
}
