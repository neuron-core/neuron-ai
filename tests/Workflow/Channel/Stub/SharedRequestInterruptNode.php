<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class SharedRequestInterruptNode extends Node
{
    public function __construct(protected ApprovalRequest $request)
    {
    }

    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $this->interrupt($this->request);

        return new SecondEvent('resumed');
    }
}
