<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

use function Amp\delay;

class AsyncDelayNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        delay(0.1);
        $state->set('completed', true);
        return new StopEvent();
    }
}
