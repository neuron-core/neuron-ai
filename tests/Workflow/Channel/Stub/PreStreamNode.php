<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use Generator;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class PreStreamNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        yield new ChunkEvent('pre');

        return new FirstEvent();
    }
}
