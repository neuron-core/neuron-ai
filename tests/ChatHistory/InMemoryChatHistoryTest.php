<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class InMemoryChatHistoryTest extends TestCase
{
    private InMemoryChatHistory $chatHistory;

    protected function setUp(): void
    {
        parent::setUp();
        // Use a small context window for testing
        $this->chatHistory = new InMemoryChatHistory(1000);
    }

    protected function tearDown(): void
    {
        $this->chatHistory->flushAll();
    }

    public function test_chat_history_instance(): void
    {
        $history = new InMemoryChatHistory();
        $this->assertInstanceOf(ChatHistoryInterface::class, $history);
    }

    public function test_chat_history_add_message(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Hello!'));
        $this->assertCount(1, $history->getMessages());
    }

    public function test_chat_history_truncate(): void
    {
        $history = new InMemoryChatHistory(13);

        $message = new UserMessage('Hello!');
        $history->addMessage($message);
        $this->assertEquals(13, $history->calculateTotalUsage());

        $message = new UserMessage('Hello!');
        $history->addMessage($message);
        $this->assertEquals(13, $history->calculateTotalUsage());
        $this->assertCount(1, $history->getMessages());
    }

    public function test_chat_history_clear(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Hello!'));
        $history->addMessage(new UserMessage('Hello2!'));
        $history->flushAll();
        $this->assertCount(0, $history->getMessages());
    }

    public function test_multiple_tool_call_pairs_are_handled_correctly(): void
    {
        // Create two different tools
        $tool1 = Tool::make('tool_1', 'First tool')
            ->setInputs(['param1' => 'value1'])
            ->setCallId('call_1');

        $tool1WithResult = Tool::make('tool_1', 'First tool')
            ->setInputs(['param1' => 'value1'])
            ->setCallId('call_1')
            ->setResult('First tool result');

        $tool2 = Tool::make('tool_2', 'Second tool')
            ->setInputs(['param2' => 'value2'])
            ->setCallId('call_2');

        $tool2WithResult = Tool::make('tool_2', 'Second tool')
            ->setInputs(['param2' => 'value2'])
            ->setCallId('call_2')
            ->setResult('Second tool result');

        // Add a large message that should trigger context window cutting
        $largeMessage = new UserMessage('Test message');
        $this->chatHistory->addMessage($largeMessage);

        // Add the first tool call pair
        $toolCall1 = new ToolCallMessage(tools: [$tool1]);
        $this->chatHistory->addMessage($toolCall1);

        $toolResult1 = new ToolResultMessage([$tool1WithResult]);
        $this->chatHistory->addMessage($toolResult1);

        // Add the second tool call pair
        $toolCall2 = new ToolCallMessage(tools: [$tool2]);
        $this->chatHistory->addMessage($toolCall2);

        $toolResult2 = new ToolResultMessage([$tool2WithResult]);
        $this->chatHistory->addMessage($toolResult2);

        $messages = $this->chatHistory->getMessages();

        $this->assertCount(5, $messages);

        // Check that we have consistent tool call/result pairs
        $toolCallNames = [];
        $toolResultNames = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage) {
                foreach ($message->getTools() as $tool) {
                    $toolCallNames[] = $tool->getName();
                }
            }
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getTools() as $tool) {
                    $toolResultNames[] = $tool->getName();
                }
            }
        }

        \sort($toolCallNames);
        \sort($toolResultNames);

        $this->assertEquals($toolCallNames, $toolResultNames, 'Tool call names should match tool result names');
    }

    public function test_regular_messages_are_removed_when_context_window_exceeded(): void
    {
        // Add several regular messages that exceed the context window
        for ($i = 0; $i < 50; $i++) {
            $message = $i % 2 === 0
                ? new UserMessage("Message $i - Lorem ipsum dolor sit amet, consectetur adipiscing elit.")
                : new AssistantMessage("Message $i - Lorem ipsum dolor sit amet, consectetur adipiscing elit.");
            $this->chatHistory->addMessage($message);
        }

        $remainingMessages = $this->chatHistory->getMessages();

        // With the context window of 1000, we should have fewer than 5 messages
        $this->assertCount(34, $remainingMessages, 'Some messages should be removed due to context window limit');
    }

    public function test_remove_intermediate_invalid_message_types(): void
    {
        $tool = Tool::make('mixed_tool', 'A mixed tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('123');

        $toolWithResult = Tool::make('mixed_tool', 'A mixed tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('123')
            ->setResult('Mixed tool result');

        // Add a mix of different message types
        $userMessage = new UserMessage('User message');
        $this->chatHistory->addMessage($userMessage);
        $this->assertCount(1, $this->chatHistory->getMessages());

        $toolCall = new ToolCallMessage(tools: [$tool]);
        $this->chatHistory->addMessage($toolCall);
        $this->assertCount(2, $this->chatHistory->getMessages());

        $toolResult = new ToolResultMessage([$toolWithResult]);
        $this->chatHistory->addMessage($toolResult);
        $this->assertCount(3, $this->chatHistory->getMessages());

        // Add a mix of different message types
        $userMessage = new UserMessage('User message');
        $this->chatHistory->addMessage($userMessage);
        // This UserMessage must be removed to restore a valid progression
        $this->assertCount(3, $this->chatHistory->getMessages());

        $messages = $this->chatHistory->getMessages();

        $this->assertInstanceOf(ToolResultMessage::class, \end($messages));
    }

    public function test_double_assistant_messages(): void
    {
        $userMessage = new UserMessage('User message');
        $this->chatHistory->addMessage($userMessage);
        $assistantMessage = new AssistantMessage('Assistant message 1');
        $this->chatHistory->addMessage($assistantMessage);
        $assistantMessage2 = new AssistantMessage('Assistant message 2');
        $this->chatHistory->addMessage($assistantMessage2);

        $messages = $this->chatHistory->getMessages();

        $this->assertCount(2, $messages);
        $this->assertInstanceOf(AssistantMessage::class, \end($messages));
        $this->assertEquals('Assistant message 1', \end($messages)->getContent());
    }

    public function test_empty_history_if_no_user_message(): void
    {
        $this->chatHistory->addMessage(new AssistantMessage('Test message'));
        $this->assertEmpty($this->chatHistory->getMessages());
    }

    public function test_remove_messages_before_the_first_user_message(): void
    {
        $this->chatHistory->addMessage(new AssistantMessage('Test message'));
        $this->chatHistory->addMessage(new UserMessage('Test message'));
        $this->assertCount(1, $this->chatHistory->getMessages());
        $this->assertInstanceOf(UserMessage::class, $this->chatHistory->getMessages()[0]);
    }
}
