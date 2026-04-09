<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Execution\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ImageProcessNode extends Node
{
    public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('processedImage', 'processed_image.jpg');
        return new StopEvent();
    }
}
