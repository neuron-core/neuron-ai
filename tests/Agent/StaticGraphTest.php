<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StartNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

class StaticGraphTest extends TestCase
{
    public function test_every_agent_graph_registers_both_inference_routes(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi')))
            ->setInstructions('test');

        $agent->chat(new UserMessage('hello'));

        $map = $agent->getEventNodeMap();

        $this->assertInstanceOf(StartNode::class, $map[AgentStartEvent::class] ?? null);
        $this->assertInstanceOf(ChatNode::class, $map[AIInferenceEvent::class] ?? null);
        $this->assertInstanceOf(StructuredOutputNode::class, $map[StructuredInferenceEvent::class] ?? null);
        $this->assertInstanceOf(ToolNode::class, $map[ToolCallEvent::class] ?? null);
    }
}
