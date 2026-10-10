<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\AgentStartNode;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Workflow\Persistence\Stub\RecordingPersistence;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

/**
 * What a turn costs its workflow store. Every write is a transaction on a
 * database backend, so a record that protects nothing is not written.
 */
class AgentDurableWritesTest extends TestCase
{
    public function test_a_tool_round_writes_once_per_inference_tool_execution_and_step(): void
    {
        $persistence = new RecordingPersistence();
        $agent = Agent::make(workflowId: 'thread_1')
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'php'])]),
                new AssistantMessage('Done'),
            ))
            ->addTool(new SearchTool())
            ->setPersistence($persistence);

        $runId = $agent->chat(new UserMessage('Find php'))->getRunId();
        $record = static fn (string $name): string => "{$runId}/{$name}";

        $this->assertSame([
            ['__control', '__ignition'],
            [$record(AgentStartNode::class . '-0'), '__control'],
            [$record(ChatNode::class . '-1::inference')],
            [$record(ChatNode::class . '-1'), '__control'],
            [$record(ToolNode::class . '-2::tool.call_1.0')],
            [$record(ToolNode::class . '-2'), '__control'],
            [$record(ChatNode::class . '-3::inference')],
            [$record(ChatNode::class . '-3'), '__control'],
            // The step that ends the run is settled by deleting the run.
            [],
        ], $persistence->writes);
    }
}
