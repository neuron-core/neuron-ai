<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use Generator;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class StreamingImageProcessNode extends Node
{
    public function __invoke(ImageProcessEvent $event, WorkflowState $state): Generator
    {
        $state->set('streaming_image_executed', true);

        yield new ChunkEvent('image-1');
        yield new ChunkEvent('image-2');

        return new StopEvent(result: 'streamed_image');
    }
}
