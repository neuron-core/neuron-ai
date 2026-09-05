<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use RuntimeException;

class RetryingTextProcessNode extends Node
{
    public function __construct(protected bool $fail = false)
    {
    }

    public function __invoke(SecondTextProcessEvent $event, WorkflowState $state): StopEvent
    {
        if ($this->fail) {
            throw new RuntimeException('Branch failed after its state was persisted.');
        }

        return new StopEvent($state->get('branch_value'));
    }
}
