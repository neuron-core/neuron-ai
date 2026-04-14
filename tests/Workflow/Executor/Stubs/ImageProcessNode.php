<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ImageProcessNode extends Node
{
    public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
    {
        return new StopEvent(result: 'processed_image.jpg');
    }
}
