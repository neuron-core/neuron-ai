<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

use function Amp\delay;

class SlowImageProcessNode extends Node
{
    public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
    {
        delay(0.1);
        return new StopEvent(result: 'processed_image.jpg');
    }
}
