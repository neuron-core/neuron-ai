<?php

namespace NeuronAI\Tests;

use NeuronAI\Agent;
use NeuronAI\AgentInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\RAG;
use NeuronAI\SystemPrompt;
use NeuronAI\Tools\Tool;
use NeuronAI\Chat\Messages\ToolCallMessage;
use PHPUnit\Framework\TestCase;

class NeuronAITest extends TestCase
{
    /**
     * Sets up the fixture, for example, open a network connection.
     * This method is called before a test is executed.
     */
    public function setUp(): void
    {
    }

    public function test_agent_instance()
    {
        $neuron = new Agent();
        $this->assertInstanceOf(AgentInterface::class, $neuron);
        $this->assertInstanceOf(ChatHistoryInterface::class, $neuron->resolveChatHistory());
        $this->assertInstanceOf(InMemoryChatHistory::class, $neuron->resolveChatHistory());

        $neuron = new RAG();
        $this->assertInstanceOf(Agent::class, $neuron);
    }

    public function test_system_instructions()
    {
        $system = new SystemPrompt(["Agent"]);
        $this->assertEquals("# IDENTITY AND PURPOSE".PHP_EOL."Agent", $system);
    }

    public function test_message_instance()
    {
        $tools = [
            new Tool('example', 'example')
        ];

        $this->assertInstanceOf(Message::class, new UserMessage(''));
        $this->assertInstanceOf(Message::class, new AssistantMessage(''));
        $this->assertInstanceOf(Message::class, new ToolCallMessage('', $tools));
    }
}
