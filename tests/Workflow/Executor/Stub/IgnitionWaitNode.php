<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class IgnitionWaitNode extends Node
{
    public function __invoke(IgnitionStartEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('ignited_with', $event->message);

        $payload = $this->awaitEvent('go');

        $state->set('answer', $payload['answer'] ?? null);

        return new StopEvent('done');
    }
}
