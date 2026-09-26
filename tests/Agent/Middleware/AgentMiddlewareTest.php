<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Tests\Agent\Middleware\Stub\PlainWorkflowNode;
use NeuronAI\Tests\Agent\Middleware\Stub\RecordingAgentMiddleware;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class AgentMiddlewareTest extends TestCase
{
    public function test_typed_hooks_fire_in_agent_context(): void
    {
        $middleware = new RecordingAgentMiddleware();
        $node = new ToolNode();
        $state = new AgentState();
        $state->request = new InferenceRequest('instructions', []);
        $event = new AIInferenceEvent();
        $result = new AgentOutputEvent();
        $resources = AgentResourcesFactory::make();

        $middleware->before($node, $event, $state, $resources);
        $middleware->after($node, $result, $state, $resources);

        $this->assertSame([[$node, $event, $state, $resources]], $middleware->beforeArguments);
        $this->assertSame([[$node, $result, $state, $resources]], $middleware->afterArguments);
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

    public function test_after_is_skipped_outside_agent_context(): void
    {
        $middleware = new RecordingAgentMiddleware();

        $middleware->after(new ToolNode(), new StartEvent(), new WorkflowState(), AgentResourcesFactory::make());
        $middleware->after(new ToolNode(), new StartEvent(), new AgentState(), new WorkflowResources());

        $this->assertSame(0, $middleware->afterCalls);
        $this->assertSame(0, $middleware->mismatchCalls);
    }

    public function test_global_middleware_runs_before_node_middleware_around_every_agent_node(): void
    {
        $log = [];
        $agent = Agent::make()
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'php'])]),
                new AssistantMessage('Done'),
            ))
            ->addTool(new SearchTool())
            ->addGlobalMiddleware($this->logging('global', $log))
            ->addMiddleware(InferenceNode::class, $this->logging('inference', $log));

        $agent->chat(new UserMessage('Search'));

        $this->assertSame([
            'global.before:AgentStartNode', 'global.after:AgentStartNode:AIInferenceEvent',
            'global.before:ChatNode', 'inference.before:ChatNode',
            'global.after:ChatNode:ToolCallEvent', 'inference.after:ChatNode:ToolCallEvent',
            'global.before:ToolNode', 'global.after:ToolNode:AIInferenceEvent',
            'global.before:ChatNode', 'inference.before:ChatNode',
            'global.after:ChatNode:AgentOutputEvent', 'inference.after:ChatNode:AgentOutputEvent',
            'global.before:AgentEndNode', 'global.after:AgentEndNode:StopEvent',
        ], $log);
    }

    public function test_a_failing_middleware_short_circuits_the_inference(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Never produced'));
        $agent = Agent::make()->setAiProvider($provider)->setThreadId('short-circuit');
        $agent->addMiddleware(InferenceNode::class, new class () extends AgentMiddleware {
            protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
            {
                throw new RuntimeException('Blocked by policy');
            }
        });

        try {
            $agent->chat(new UserMessage('Hi'));
            $this->fail('The middleware failure must stop the run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Blocked by policy', $exception->getMessage());
        }

        $provider->assertNothingSent();
        $this->assertSame([], $agent->getChatHistory()->getMessages());
    }

    /**
     * @param string[] $log
     */
    protected function logging(string $name, array &$log): WorkflowMiddleware
    {
        return new class ($name, $log) implements WorkflowMiddleware {
            /**
             * @param string[] $log
             */
            public function __construct(protected string $name, protected array &$log)
            {
            }

            public function before(NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void
            {
                $this->log[] = "{$this->name}.before:" . (new ReflectionClass($node))->getShortName();
            }

            public function after(NodeInterface $node, Event $result, WorkflowState $state, WorkflowResources $resources): void
            {
                $this->log[] = "{$this->name}.after:" . (new ReflectionClass($node))->getShortName()
                    . ':' . (new ReflectionClass($result))->getShortName();
            }
        };
    }
}
