<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability\Stubs;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class EmittingNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $this->emit(new CustomTestEvent('emitted-from-node'));

        return new StopEvent('done');
    }
}
