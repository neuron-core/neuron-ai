<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function count;
use function end;
use function sort;

class ChatHistoryTest extends TestCase
{
    private ChatHistory $chatHistory;

    protected function setUp(): void
    {
        parent::setUp();
        // Use a small context window for testing
        $this->chatHistory = $this->history(1000);
    }

    protected function tearDown(): void
    {
        $this->chatHistory->flushAll();
    }

    public function test_chat_history_add_message(): void
    {
        $history = $this->history();
        $history->addMessage(new UserMessage('Hello!'));
        $this->assertCount(1, $history->getMessages());
    }

    public function test_user_message_after_tool_call_is_rejected(): void
    {
        $history = $this->history();
        $history->addMessage(new UserMessage('Delete the file'));
        $history->addMessage(new ToolCallMessage(null, [
            ToolCall::make('delete_file', description: 'd')->setCallId('c1')->setInputs(['path' => '/tmp/x']),
        ]));

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('cannot directly follow a ToolCallMessage');

        $history->addMessage(new UserMessage('Another question'));
    }

    public function test_tool_result_after_tool_call_is_allowed(): void
    {
        $tool = ToolCall::make('delete_file', description: 'd')->setCallId('c1')->setInputs(['path' => '/tmp/x']);
        $toolWithResult = (clone $tool)->setResult('File deleted');

        $history = $this->history();
        $history->addMessage(new UserMessage('Delete the file'));
        $history->addMessage(new ToolCallMessage(null, [$tool]));
        $history->addMessage(new ToolResultMessage([$toolWithResult]));

        $this->assertCount(3, $history->getMessages());
    }

    public function test_chat_history_truncate_and_validate(): void
    {
        $history = $this->history(13);

        $message = new UserMessage('Hello!');
        $history->addMessage($message);
        $this->assertCount(1, $history->getMessages());

        $message = new AssistantMessage('Hello!');
        $message->setUsage(new Usage(15, 12));
        $history->addMessage($message);

        // The trimmer uses bidirectional search to find the closest UserMessage.
        // Even though both messages exceed the context window (27 tokens > 13),
        // the backward search finds the UserMessage at index 0 and keeps both.
        // This is the intentional trade-off: minimize context loss while ensuring valid sequence.
        $this->assertCount(2, $history->getMessages());
    }

    public function test_chat_history_clear(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 1: expected role assistant, got user');

        $history = $this->history();
        $history->addMessage(new UserMessage('Hello!'));
        $history->addMessage(new UserMessage('Hello2!'));
    }

    public function test_multiple_tool_call_pairs_are_handled_correctly(): void
    {
        // Create two different tools
        $tool1 = ToolCall::make('tool_1', description: 'First tool')
            ->setInputs(['param1' => 'value1'])
            ->setCallId('call_1');

        $tool1WithResult = ToolCall::make('tool_1', description: 'First tool')
            ->setInputs(['param1' => 'value1'])
            ->setCallId('call_1')
            ->setResult('First tool result');

        $tool2 = ToolCall::make('tool_2', description: 'Second tool')
            ->setInputs(['param2' => 'value2'])
            ->setCallId('call_2');

        $tool2WithResult = ToolCall::make('tool_2', description: 'Second tool')
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
                foreach ($message->getToolCalls() as $tool) {
                    $toolCallNames[] = $tool->getName();
                }
            }
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
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
        // AI providers report inputTokens as cumulative context, so the last checkpoint
        // (inputTokens + outputTokens) = 1000 + 150 = 1150 represents the total tokens.
        for ($i = 1; $i <= 10; $i++) {
            $message = $i % 2 === 0
                ? (new AssistantMessage("Message $i - Lorem ipsum dolor sit amet, consectetur adipiscing elit."))->setUsage(new Usage(100 * $i, 150))
                : new UserMessage("Message $i - Lorem ipsum dolor sit amet, consectetur adipiscing elit.");
            $this->chatHistory->addMessage($message);
        }

        // Trimming logic:
        // - Total tokens = 1150 (from last checkpoint)
        // - Threshold = 1150 - 1000 = 150 (need to remove at least 150 tokens)
        // - First checkpoint with tokens >= 150 is at index 1 (tokens=350)
        // - Trim at index 2, keeping messages 2-9 (8 messages)
        // - New total = 1150 - 350 = 800 (within the context window)
        $this->assertCount(8, $this->chatHistory->getMessages());

        // Verify we're within the context window
        $this->assertLessThanOrEqual(1000, $this->chatHistory->calculateTotalUsage());

        // Verify the alternation is valid (starts with user, ends with assistant)
        $messages = $this->chatHistory->getMessages();
        $this->assertEquals('user', $messages[0]->getRole());
        $this->assertEquals('assistant', end($messages)->getRole());
    }

    public function test_remove_intermediate_invalid_message_types(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 3: expected role assistant, got user');

        $tool = ToolCall::make('mixed_tool', description: 'A mixed tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('123');

        $toolWithResult = ToolCall::make('mixed_tool', description: 'A mixed tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('123')
            ->setResult('Mixed tool result');

        // Add a mix of different message types
        $userMessage = new UserMessage('User message');
        $this->chatHistory->addMessage($userMessage);

        $toolCall = new ToolCallMessage(tools: [$tool]);
        $toolCall->setUsage(new Usage(120, 150));
        $this->chatHistory->addMessage($toolCall);

        $toolResult = new ToolResultMessage([$toolWithResult]);
        $this->chatHistory->addMessage($toolResult);

        // Adding another user message after the tool result is invalid
        // (should be an assistant message)
        $userMessage = new UserMessage('User message');
        $this->chatHistory->addMessage($userMessage);
    }

    public function test_double_assistant_messages(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 2: expected role user, got assistant');

        $userMessage = new UserMessage('User message');
        $this->chatHistory->addMessage($userMessage);
        $assistantMessage = new AssistantMessage('Assistant message 1');
        $assistantMessage->setUsage(new Usage(12, 15));
        $this->chatHistory->addMessage($assistantMessage);
        $assistantMessage2 = new AssistantMessage('Assistant message 2');
        $this->chatHistory->addMessage($assistantMessage2);
    }

    public function test_history_if_no_user_message(): void
    {
        // A single assistant message is invalid - should throw an exception
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 0: expected role user, got assistant');

        $this->chatHistory->addMessage(new AssistantMessage('Test message'));
    }

    public function test_invalid_assistant_message_before_the_first_user_message(): void
    {
        // Assistant followed by user is an invalid sequence - should throw exception
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid message sequence at position 0: expected role user, got assistant');

        $this->chatHistory->addMessage(new AssistantMessage('Test message'));
        $this->chatHistory->addMessage(new UserMessage('Test message'));
    }

    public function test_find_trim_point_progressively_exceeds_context_window(): void
    {
        // Use a small context window to trigger trimming
        $history = $this->history(500);

        // AI providers report input_tokens as CUMULATIVE (including all prior context).
        // So the last checkpoint (input + output) IS the total tokens used.
        //
        // Checkpoint 1: Usage(150, 50) → total = 200 (within the window)
        // Checkpoint 2: Usage(350, 50) → total = 400 (within the window, input includes pair 1)
        // Checkpoint 3: Usage(550, 50) → total = 600 (exceeds 500, triggers trim)

        // Pair 1: stays within the window (total: 200)
        $history->addMessage(new UserMessage('User message 1'));
        $assistant1 = new AssistantMessage('Assistant message 1');
        $assistant1->setUsage(new Usage(150, 50));
        $history->addMessage($assistant1);
        $this->assertCount(2, $history->getMessages());

        // Pair 2: still within the window (total: 400)
        $history->addMessage(new UserMessage('User message 2'));
        $assistant2 = new AssistantMessage('Assistant message 2');
        $assistant2->setUsage(new Usage(350, 50)); // input includes prior context
        $history->addMessage($assistant2);
        $this->assertCount(4, $history->getMessages());

        // Pair 3: exceeds the window (total: 600) - triggers trimming
        $history->addMessage(new UserMessage('User message 3'));
        $assistant3 = new AssistantMessage('Assistant message 3');
        $assistant3->setUsage(new Usage(550, 50)); // cumulative total = 600
        $history->addMessage($assistant3);

        // After trimming, older messages are removed to fit within window
        $messages = $history->getMessages();

        // Verify we stay within the context window
        $this->assertLessThanOrEqual(500, $history->calculateTotalUsage());

        // Verify alternation is maintained (starts with user, ends with assistant)
        $this->assertEquals('user', $messages[0]->getRole());
        $this->assertEquals('assistant', end($messages)->getRole());

        // Verify we have an even number of messages (complete pairs)
        $this->assertEquals(0, count($messages) % 2, 'Message count should be even (complete pairs)');
    }

    public function test_find_trim_point_preserves_tool_call_result_pairs(): void
    {
        // Use a small context window so trimming is triggered
        $history = $this->history(300);

        // Create tools for multiple tool call/result pairs
        $tool1 = ToolCall::make('search_tool', description: 'Search for information')
            ->setInputs(['query' => 'test query 1'])
            ->setCallId('call_1');

        $tool1WithResult = ToolCall::make('search_tool', description: 'Search for information')
            ->setInputs(['query' => 'test query 1'])
            ->setCallId('call_1')
            ->setResult('Search result 1');

        $tool2 = ToolCall::make('weather_tool', description: 'Get weather info')
            ->setInputs(['location' => 'London'])
            ->setCallId('call_2');

        $tool2WithResult = ToolCall::make('weather_tool', description: 'Get weather info')
            ->setInputs(['location' => 'London'])
            ->setCallId('call_2')
            ->setResult('Sunny, 25°C');

        // Pair 1: User + ToolCall + ToolResult + Assistant
        // This pair will be trimmed when the context window is exceeded
        $history->addMessage(new UserMessage('What is the weather?'));
        $toolCall1 = new ToolCallMessage(tools: [$tool1]);
        $toolCall1->setUsage(new Usage(50, 30)); // Checkpoint 1: total = 80
        $history->addMessage($toolCall1);
        $history->addMessage(new ToolResultMessage([$tool1WithResult]));
        $assistant1 = new AssistantMessage('Based on the search...');
        $assistant1->setUsage(new Usage(120, 40)); // Checkpoint 2: total = 160 (within 300)
        $history->addMessage($assistant1);

        $this->assertCount(4, $history->getMessages());

        // Pair 2: User + ToolCall + ToolResult + Assistant
        // This pair will exceed the context window and trigger trimming
        // The trim point could fall in the middle of the tool call pair
        $history->addMessage(new UserMessage('Tell me more'));
        $toolCall2 = new ToolCallMessage(tools: [$tool2]);
        $toolCall2->setUsage(new Usage(200, 35)); // Checkpoint 3: total = 235
        $history->addMessage($toolCall2);
        $history->addMessage(new ToolResultMessage([$tool2WithResult]));
        $assistant2 = new AssistantMessage('The weather in London...');
        $assistant2->setUsage(new Usage(350, 50)); // Checkpoint 4: total = 400 (exceeds 300!)
        $history->addMessage($assistant2);

        $messages = $history->getMessages();

        // Verify we stay within the context window after trimming
        $this->assertLessThanOrEqual(300, $history->calculateTotalUsage());
        $this->assertGreaterThan(0, $history->calculateTotalUsage());

        // Verify the message sequence is valid
        // Should start with UserMessage (not ToolResultMessage or ToolCallMessage)
        $this->assertInstanceOf(UserMessage::class, $messages[0]);

        // Verify ToolResultMessage is always preceded by ToolCallMessage
        $previousMessage = null;
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $this->assertInstanceOf(
                    ToolCallMessage::class,
                    $previousMessage,
                    'ToolResultMessage must be preceded by ToolCallMessage'
                );
            }
            $previousMessage = $message;
        }

        // Verify we end with an assistant message (not a tool result or tool call)
        $lastMessage = end($messages);
        $this->assertInstanceOf(AssistantMessage::class, $lastMessage);

        // Verify alternation: user -> (tool_call -> tool_result)* -> assistant
        $expectingUser = true;
        foreach ($messages as $index => $message) {
            if ($message instanceof ToolResultMessage) {
                // Tool result doesn't change the expected role for the next regular message
                $expectingUser = false;
                continue;
            }
            if ($message instanceof ToolCallMessage) {
                // After a tool call, expect a tool result
                $expectingUser = true;
                continue;
            }

            $expectedRole = $expectingUser ? 'user' : 'assistant';
            $this->assertEquals(
                $expectedRole,
                $message->getRole(),
                "Message at index $index has wrong role. Expected: $expectedRole, Got: {$message->getRole()}"
            );
            $expectingUser = !$expectingUser;
        }
    }

    public function test_loading_is_deferred_to_first_use(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');

        // Stored after construction: an eager load would miss it.
        $store->append('thread', new UserMessage('Stored later'));

        $this->assertSame('Stored later', $history->getMessages()[0]->getContent());
    }

    public function test_a_failed_load_is_retried_on_the_next_access(): void
    {
        $store = new class () extends InMemoryMessageStore {
            public bool $failing = true;

            public function loadActive(string $threadId): array
            {
                if ($this->failing) {
                    $this->failing = false;
                    throw new RuntimeException('Connection lost');
                }

                return parent::loadActive($threadId);
            }
        };
        $store->append('thread', new UserMessage('Hello'));
        $history = new ChatHistory($store, 'thread');

        try {
            $history->getMessages();
            $this->fail('The first load should fail.');
        } catch (RuntimeException) {
        }

        $this->assertCount(1, $history->getMessages());
    }

    public function test_a_message_already_in_the_context_is_skipped(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');
        $message = new UserMessage('Hello');

        $history->addMessage($message);
        $history->addMessage($message);

        $this->assertCount(1, $history->getMessages());
        $this->assertCount(1, $store->loadAll('thread'));
    }

    public function test_trimmed_messages_are_archived_in_the_store(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread', 1000);

        for ($i = 1; $i <= 10; $i++) {
            $history->addMessage($i % 2 === 0
                ? (new AssistantMessage("Message {$i}"))->setUsage(new Usage(100 * $i, 150))
                : new UserMessage("Message {$i}"));
        }

        $this->assertCount(8, $history->getMessages());
        $this->assertSame($this->ids($history->getMessages()), $this->ids($store->loadActive('thread')));
        $this->assertCount(10, $store->loadAll('thread'));
    }

    public function test_an_invalid_sequence_is_rejected_before_anything_is_stored(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');
        $history->addMessage(new UserMessage('Hello'));

        try {
            $history->addMessage(new UserMessage('Hello again'));
            $this->fail('Two consecutive user messages should be rejected.');
        } catch (ChatHistoryException) {
        }

        $this->assertCount(1, $store->loadAll('thread'));
        $this->assertCount(1, $history->getMessages());
    }

    public function test_usage_is_measured_on_a_freshly_loaded_history(): void
    {
        $store = new InMemoryMessageStore();
        $writer = new ChatHistory($store, 'thread');
        $writer->addMessage(new UserMessage('Hello'));
        $writer->addMessage((new AssistantMessage('Hi'))->setUsage(new Usage(120, 30)));

        $reader = new ChatHistory($store, 'thread');

        $this->assertSame(150, $reader->calculateTotalUsage());
        $this->assertSame($writer->calculateTotalUsage(), $reader->calculateTotalUsage());
    }

    public function test_flush_all_removes_the_thread_from_the_store(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');
        $history->addMessage(new UserMessage('Hello'));

        $history->flushAll();

        $this->assertSame([], $history->getMessages());
        $this->assertSame([], $store->loadAll('thread'));
    }

    protected function history(int $contextWindow = 50000): ChatHistory
    {
        return new ChatHistory(new InMemoryMessageStore(), 'thread', $contextWindow);
    }

    /**
     * @param Message[] $messages
     * @return string[]
     */
    protected function ids(array $messages): array
    {
        return array_map(fn (Message $message): string => $message->getId(), $messages);
    }
}
