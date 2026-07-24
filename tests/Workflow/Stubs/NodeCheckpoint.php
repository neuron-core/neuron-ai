<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class NodeCheckpoint extends Node
{
    /**
     * @throws WorkflowException
     */
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $checkpoint = $this->checkpoint('test', fn (): string => 'test');
        $state->set('checkpoint', $checkpoint);

        // interrupt() returns the inbound wake (plain array) on resume.
        $wake = $this->interrupt(new ApprovalRequest('what do you mean?'));
        $state->set('feedback', is_array($wake) ? (string) ($wake['message'] ?? '') : null);

        return new StopEvent();
    }
}
