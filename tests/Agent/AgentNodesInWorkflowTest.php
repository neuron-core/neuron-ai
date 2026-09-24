<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Nodes\AgentStartNode;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolRegistry;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowResources;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Agent nodes composed into a plain Workflow read what they need from the
 * AgentResources that workflow provides, so they resume like an Agent does.
 */
class AgentNodesInWorkflowTest extends TestCase
{
    public function test_a_composed_tool_loop_resumes_after_an_approval(): void
    {
        $persistence = new InMemoryPersistence();
        $store = new InMemoryMessageStore();
        $make = fn (FakeAIProvider $provider): Workflow => Workflow::make('custom', new AgentState())
            ->setPersistence($persistence)
            ->setStartEvent(new AgentStartEvent([new UserMessage('Search PHP')]))
            ->setResources(fn (): AgentResources => new AgentResources(
                $provider,
                new ChatHistory($store, 'custom'),
                new SystemMessage('Be helpful'),
                new ToolRegistry([(new SearchTool())->requireApproval()]),
            ))
            ->addNodes([new AgentStartNode(), new ChatNode(), new ToolNode(), new AgentEndNode()]);

        $paused = $make(new FakeAIProvider(new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'php'])])))->run();
        $this->assertTrue($paused->isInterrupted());

        $provider = new FakeAIProvider(new AssistantMessage('Done'));
        $completed = $make($provider)->run(ExecutionRequest::resume(['call_1' => 'approve']));

        $this->assertFalse($completed->isInterrupted());
        $this->assertSame(['search'], array_map(fn (ToolInterface $tool): string => $tool->getName(), $provider->getRecorded()[0]->tools));
    }

    public function test_agent_nodes_without_agent_resources_fail_when_the_graph_is_built(): void
    {
        $workflow = Workflow::make('custom', new AgentState())
            ->setStartEvent(new AgentStartEvent([new UserMessage('Hello')]))
            ->addNodes([new AgentStartNode(), new ChatNode(), new AgentEndNode()]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('needs ' . AgentResources::class . ', but the workflow provides ' . WorkflowResources::class);

        $workflow->run();
    }
}
