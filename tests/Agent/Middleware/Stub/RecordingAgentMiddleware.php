<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware\Stub;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class RecordingAgentMiddleware extends AgentMiddleware
{
    public int $agentCalls = 0;
    public int $mismatchCalls = 0;
    public int $afterCalls = 0;

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state): void
    {
        $this->agentCalls++;
    }

    protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state): void
    {
        $this->afterCalls++;
    }

    protected function onAgentContextMismatch(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $this->mismatchCalls++;
    }
}
