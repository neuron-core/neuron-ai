<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use Generator;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ChunkStreamingNode extends Node
{
    public function __construct(protected int $chunks = 3, protected string $prefix = 'chunk')
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        for ($i = 1; $i <= $this->chunks; $i++) {
            yield new ChunkEvent($this->prefix . '-' . $i);
        }

        return new StopEvent();
    }
}
