<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

class NodeCheckpoint extends Node
{
    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $checkpoint = $this->checkpoint('test', fn (): string => 'test');
        $state->set('checkpoint', $checkpoint);

        $feedback = $this->interrupt(new InterruptRequest([], 'what do you mean?'));
        $state->set('feedback', $feedback->getMessage());

        return new StopEvent();
    }
}
