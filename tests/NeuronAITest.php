<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\RAG;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;
use const PHP_EOL;

class NeuronAITest extends TestCase
{
    public function test_agent_instance(): void
    {
        $neuron = new Agent();
        $this->assertInstanceOf(AgentInterface::class, $neuron);
        $this->assertInstanceOf(ChatHistoryInterface::class, $neuron->getChatHistory());
        $this->assertInstanceOf(InMemoryChatHistory::class, $neuron->getChatHistory());

        $neuron = new RAG();
        $this->assertInstanceOf(Agent::class, $neuron);
    }

    public function test_system_instructions(): void
    {
        $system = new SystemPrompt(["Agent"]);
        $this->assertEquals("# IDENTITY AND PURPOSE".PHP_EOL."Agent", $system);

        $agent = new class () extends Agent {
            public function instructions(): string
            {
                return 'Hello';
            }
        };
        $this->assertEquals('Hello', $agent->resolveInstructions());
        $agent->setInstructions('Hello2');
        $this->assertEquals('Hello2', $agent->resolveInstructions());
    }

    public function test_message_instance(): void
    {
        $tools = [
            new Tool('example', 'example')
        ];

        $this->assertInstanceOf(Message::class, new UserMessage(''));
        $this->assertInstanceOf(Message::class, new AssistantMessage(''));
        $this->assertInstanceOf(Message::class, new ToolCallMessage(tools: $tools));
    }
}
