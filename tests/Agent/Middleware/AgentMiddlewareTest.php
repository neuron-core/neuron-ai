<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Tests\Agent\Middleware\Stub\PlainWorkflowNode;
use NeuronAI\Tests\Agent\Middleware\Stub\RecordingAgentMiddleware;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class AgentMiddlewareTest extends TestCase
{
    public function test_typed_hooks_fire_in_agent_context(): void
    {
        $middleware = new RecordingAgentMiddleware();
        $node = new ToolNode(new InMemoryChatHistory());
        $event = new AIInferenceEvent('instructions', []);

        $middleware->before($node, $event, new AgentState());
        $middleware->after($node, $event, new AgentState());

        $this->assertSame(1, $middleware->agentCalls);
        $this->assertSame(1, $middleware->afterCalls);
        $this->assertSame(0, $middleware->mismatchCalls);
    }

    public function test_mismatch_hook_fires_outside_agent_context(): void
    {
        $middleware = new RecordingAgentMiddleware();

        // Non-agent node.
        $middleware->before(new PlainWorkflowNode(), new StartEvent(), new AgentState());
        // Agent node with a plain workflow state.
        $middleware->before(new ToolNode(new InMemoryChatHistory()), new StartEvent(), new WorkflowState());

        $this->assertSame(0, $middleware->agentCalls);
        $this->assertSame(2, $middleware->mismatchCalls);
    }

    public function test_mismatch_is_not_reported_twice_by_after(): void
    {
        $middleware = new RecordingAgentMiddleware();
        $node = new PlainWorkflowNode();

        $middleware->before($node, new StartEvent(), new AgentState());
        $middleware->after($node, new StartEvent(), new AgentState());

        $this->assertSame(1, $middleware->mismatchCalls, 'A mismatch reaction is a before()-only concern');
    }

}
