<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function end;
use function sort;

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

    public function test_chat_history_truncate_and_validate(): void
    {
        $history = new InMemoryChatHistory(13);

        $message = new UserMessage('Hello!');
        $history->addMessage($message);
        $this->assertCount(1, $history->getMessages());

        $message = new AssistantMessage('Hello!');
        $message->setUsage(new Usage(15, 12));
        $history->addMessage($message);

        // The trimmer will keep only the assistant message because the user message will have 15 tokens > context_window
        // Only the AssistantMessage will remain
        // Since a chat history can't end with an assistant message, it will be removed during validation
        // The final result is no messages
        $this->assertCount(0, $history->getMessages());
    }

    public function test_chat_history_clear(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 1: expected role assistant or model, got user');

        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Hello!'));
        $history->addMessage(new UserMessage('Hello2!'));
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

        sort($toolCallNames);
        sort($toolResultNames);

        $this->assertEquals($toolCallNames, $toolResultNames, 'Tool call names should match tool result names');
    }

    public function test_regular_messages_are_removed_when_context_window_exceeded(): void
    {
        // Add several regular messages that exceed the context window.
        // Assistant messages have usage: (200,150), (400,150), (600,150), (800,150), (1000,150)
        // Total tokens from checkpoints = 200+400+600+800+1000 + (5*150) = 3000 + 750 = 3750
        // But checkpoints only capture individual assistant message tokens
        for ($i = 1; $i <= 10; $i++) {
            $message = $i % 2 === 0
                ? (new AssistantMessage("Message $i - Lorem ipsum dolor sit amet, consectetur adipiscing elit."))->setUsage(new Usage(100 * $i, 150))
                : new UserMessage("Message $i - Lorem ipsum dolor sit amet, consectetur adipiscing elit.");
            $this->chatHistory->addMessage($message);
        }

        // With smart trim preserving pairs:
        // - First checkpoint at index 1 (Message 2) has usage (200,150) = 350 tokens
        // - Smart trim ensures we trim at pair boundaries
        // - Result: 6 messages kept (messages 5-10)
        $this->assertCount(6, $this->chatHistory->getMessages());

        // Verify we're within context window
        $this->assertLessThanOrEqual(1000, $this->chatHistory->calculateTotalUsage());

        // Verify the alternation is valid (starts with user, ends with assistant)
        $messages = $this->chatHistory->getMessages();
        $this->assertEquals('user', $messages[0]->getRole());
        $this->assertEquals('assistant', end($messages)->getRole());
    }

    public function test_remove_intermediate_invalid_message_types(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 3: expected role assistant or model, got user');

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
        $toolCall->setUsage(new Usage(120, 150));
        $this->chatHistory->addMessage($toolCall);
        $this->assertCount(2, $this->chatHistory->getMessages());

        $toolResult = new ToolResultMessage([$toolWithResult]);
        $this->chatHistory->addMessage($toolResult);
        $this->assertCount(3, $this->chatHistory->getMessages());

        // Adding another user message after tool result is invalid
        // (should be assistant message)
        $userMessage = new UserMessage('User message');
        $this->chatHistory->addMessage($userMessage);
    }

    public function test_double_assistant_messages(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 2: expected role user or developer, got assistant');

        $userMessage = new UserMessage('User message');
        $this->chatHistory->addMessage($userMessage);
        $assistantMessage = new AssistantMessage('Assistant message 1');
        $assistantMessage->setUsage(new Usage(12, 15));
        $this->chatHistory->addMessage($assistantMessage);
        $assistantMessage2 = new AssistantMessage('Assistant message 2');
        $this->chatHistory->addMessage($assistantMessage2);
    }

    public function test_empty_history_if_no_user_message(): void
    {
        // A single assistant message is invalid - should throw exception
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 0: expected role user or developer, got assistant');

        $this->chatHistory->addMessage(new AssistantMessage('Test message'));
    }

    public function test_remove_messages_before_the_first_user_message(): void
    {
        // Assistant followed by user is invalid sequence - should throw exception
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 0: expected role user or developer, got assistant');

        $this->chatHistory->addMessage(new AssistantMessage('Test message'));
        $this->chatHistory->addMessage(new UserMessage('Test message'));
    }
}
