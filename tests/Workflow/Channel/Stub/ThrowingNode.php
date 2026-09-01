<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use RuntimeException;

class ThrowingNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        throw new RuntimeException('node exploded');
    }
}
