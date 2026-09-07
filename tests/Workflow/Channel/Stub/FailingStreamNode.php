<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

class FailingStreamNode extends Node
{
    public function __construct(protected Throwable $error, protected bool $emitChunk = true)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        if ($this->emitChunk) {
            yield new TextChunk('msg_test', 'Partial response');
        }

        throw $this->error;
    }
}
