<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Execution\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

use function Amp\delay;

class SlowTextProcessNode extends Node
{
    public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
    {
        delay(0.1);
        $state->set('processedText', 'HELLO');
        return new StopEvent();
    }
}
