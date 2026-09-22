<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\AwaitToolResultsEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\AwaitToolResultsNode;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Nodes\AgentStartNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Support\ExecutionTestFactory;

class StaticGraphTest extends TestCase
{
    public function test_every_agent_graph_registers_both_inference_routes(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');

        $agent->chat(new UserMessage('hello'));

        $map = ExecutionTestFactory::runtime($agent)->getEventNodeMap();

        $this->assertInstanceOf(AgentStartNode::class, $map[AgentStartEvent::class] ?? null);
        $this->assertInstanceOf(ChatNode::class, $map[AIInferenceEvent::class] ?? null);
        $this->assertInstanceOf(StructuredOutputNode::class, $map[StructuredInferenceEvent::class] ?? null);
        $this->assertInstanceOf(ToolNode::class, $map[ToolCallEvent::class] ?? null);
        $this->assertInstanceOf(AwaitToolResultsNode::class, $map[AwaitToolResultsEvent::class] ?? null);
        $this->assertInstanceOf(AgentEndNode::class, $map[AgentOutputEvent::class] ?? null);
    }
}
