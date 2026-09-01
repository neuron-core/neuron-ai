<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use Generator;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class PostStreamNode extends Node
{
    public function __invoke(SecondEvent $event, WorkflowState $state): Generator
    {
        yield new ChunkEvent('post');

        return new StopEvent();
    }
}
