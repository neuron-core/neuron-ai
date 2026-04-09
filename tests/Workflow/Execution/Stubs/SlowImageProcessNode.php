<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Execution\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

use function Amp\delay;

class SlowImageProcessNode extends Node
{
    public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
    {
        delay(0.1);
        $state->set('processedImage', 'processed_image.jpg');
        return new StopEvent();
    }
}
