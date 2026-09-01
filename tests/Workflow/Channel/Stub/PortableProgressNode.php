<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use Generator;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class PortableProgressNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        yield new WorkflowProgress(50);

        return new StopEvent();
    }
}
