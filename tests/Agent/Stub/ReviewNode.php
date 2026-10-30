<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

/**
 * Asks a human to review the final answer: an approval no tool call waits for.
 */
class ReviewNode extends Node
{
    public function __invoke(AgentOutputEvent $event, AgentState $state): StopEvent
    {
        $this->interrupt(new ApprovalRequest('Review the answer before it is sent'));

        return new StopEvent();
    }
}
