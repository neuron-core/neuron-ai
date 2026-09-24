<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Tests\Agent\Middleware\Stub\PlainWorkflowNode;
use NeuronAI\Tests\Agent\Middleware\Stub\RecordingAgentMiddleware;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class AgentMiddlewareTest extends TestCase
{
    public function test_typed_hooks_fire_in_agent_context(): void
    {
        $middleware = new RecordingAgentMiddleware();
        $node = new ToolNode();
        $state = new AgentState();
        $state->request = new InferenceRequest('instructions', []);
        $event = new AIInferenceEvent();

        $middleware->before($node, $event, $state, AgentResourcesFactory::make());
        $middleware->after($node, $event, $state, AgentResourcesFactory::make());

        $this->assertSame(1, $middleware->agentCalls);
        $this->assertSame(1, $middleware->afterCalls);
        $this->assertSame(0, $middleware->mismatchCalls);
    }

    public function test_mismatch_hook_fires_outside_agent_context(): void
    {
        $middleware = new RecordingAgentMiddleware();

        // Non-agent node.
        $middleware->before(new PlainWorkflowNode(), new StartEvent(), new AgentState(), AgentResourcesFactory::make());
        // Agent node with a plain workflow state.
        $middleware->before(new ToolNode(), new StartEvent(), new WorkflowState(), AgentResourcesFactory::make());
        // Agent node and state with plain workflow resources.
        $middleware->before(new ToolNode(), new StartEvent(), new AgentState(), new WorkflowResources());

        $this->assertSame(0, $middleware->agentCalls);
        $this->assertSame(3, $middleware->mismatchCalls);
    }

    public function test_mismatch_is_not_reported_twice_by_after(): void
    {
        $middleware = new RecordingAgentMiddleware();
        $node = new PlainWorkflowNode();

        $middleware->before($node, new StartEvent(), new AgentState(), AgentResourcesFactory::make());
        $middleware->after($node, new StartEvent(), new AgentState(), AgentResourcesFactory::make());

        $this->assertSame(1, $middleware->mismatchCalls, 'A mismatch reaction is a before()-only concern');
    }

}
