<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowState;

class NodeCheckpoint extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $checkpoint = $this->checkpoint('test', fn (): string => 'test');
        $state->set('checkpoint', $checkpoint);

        $feedback = $this->interrupt(['message' => 'what do you mean?']);
        $state->set('feedback', $feedback);

        return new StopEvent();
    }
}
