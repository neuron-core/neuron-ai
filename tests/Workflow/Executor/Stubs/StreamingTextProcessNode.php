<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use Generator;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class StreamingTextProcessNode extends Node
{
    public function __invoke(Step2Event $event, WorkflowState $state): Generator
    {
        $state->set('streaming_step_executed', true);

        yield new ChunkEvent('text-1');
        yield new ChunkEvent('text-2');

        return new Step3Event('streaming done');
    }
}
