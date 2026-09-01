<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class TwoStageInterruptNode extends Node
{
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $payload = $this->interrupt(new ApprovalRequest('stage one'));

        if (!isset($payload['complete'])) {
            $this->interrupt(new ApprovalRequest('stage two'));
        }

        return new SecondEvent('resumed');
    }
}
