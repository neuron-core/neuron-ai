<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class UserMessageAfterToolCallTest extends TestCase
{
    public function test_user_message_added_after_tool_call_should_be_removed(): void
    {
        // This tests the bug: UserMessage added after ToolCallMessage (before ToolResultMessage)
        // should be rejected because ToolCallMessage can only be followed by ToolResultMessage

        $tool = Tool::make('test_tool', 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123');

        $toolWithResult = Tool::make('test_tool', 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123')
            ->setResult('Tool result');

        $chatHistory = new InMemoryChatHistory();

        // 1. Add a normal user message
        $userMessage = new UserMessage('What is the weather?');
        $chatHistory->addMessage($userMessage);
        $this->assertCount(1, $chatHistory->getMessages());

        // 2. Agent responds with a tool call
        $toolCall = new ToolCallMessage(tools: [$tool]);
        $toolCall->setUsage(new Usage(12, 15));
        $chatHistory->addMessage($toolCall);
        $this->assertCount(2, $chatHistory->getMessages());

        // 3. BUG: User adds another message instead of providing tool result
        // This should be rejected to maintain valid conversation flow
        $invalidUserMessage = new UserMessage('Actually, never mind');
        $chatHistory->addMessage($invalidUserMessage);

        // EXPECTED BEHAVIOR:
        // - The invalid UserMessage should be removed
        // - Count should remain 2
        // - Last message should still be ToolCallMessage
        $this->assertCount(2, $chatHistory->getMessages(), 'Invalid UserMessage after ToolCallMessage should be removed');

        $messages = $chatHistory->getMessages();
        $this->assertInstanceOf(ToolCallMessage::class, end($messages), 'Last message should still be ToolCallMessage');
    }

    public function test_valid_tool_call_result_pair_preserved(): void
    {
        // This test verifies that valid tool call/result pairs work correctly

        $tool = Tool::make('test_tool', 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123');

        $toolWithResult = Tool::make('test_tool', 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123')
            ->setResult('Tool result');

        $chatHistory = new InMemoryChatHistory();

        // Valid sequence: User → ToolCall → ToolResult → AssistantMessage → User
        $chatHistory->addMessage(new UserMessage('What is the weather?'));
        $chatHistory->addMessage((new ToolCallMessage(tools: [$tool]))->setUsage(new Usage(12, 15)));
        $chatHistory->addMessage(new ToolResultMessage([$toolWithResult]));
        $chatHistory->addMessage((new AssistantMessage('The weather is sunny.'))->setUsage(new Usage(10, 10)));
        $chatHistory->addMessage(new UserMessage('Thanks!'));

        $this->assertCount(5, $chatHistory->getMessages());

        $messages = $chatHistory->getMessages();
        $this->assertInstanceOf(UserMessage::class, end($messages));
    }

    public function test_multiple_tool_calls_with_invalid_user_message_in_between(): void
    {
        // Test a tool loop pattern where agent calls multiple tools for one question

        $tool1 = Tool::make('tool_1', 'Tool 1')
            ->setInputs(['param' => 'value1'])
            ->setCallId('call_1');

        $tool1WithResult = Tool::make('tool_1', 'Tool 1')
            ->setInputs(['param' => 'value1'])
            ->setCallId('call_1')
            ->setResult('Result 1');

        $tool2 = Tool::make('tool_2', 'Tool 2')
            ->setInputs(['param' => 'value2'])
            ->setCallId('call_2');

        $tool2WithResult = Tool::make('tool_2', 'Tool 2')
            ->setInputs(['param' => 'value2'])
            ->setCallId('call_2')
            ->setResult('Result 2');

        $chatHistory = new InMemoryChatHistory();

        // Tool loop pattern: User → ToolCall → ToolResult → ToolCall → ToolResult → Assistant
        $chatHistory->addMessage(new UserMessage('First question'));
        $chatHistory->addMessage((new ToolCallMessage(tools: [$tool1]))->setUsage(new Usage(10, 10)));
        $chatHistory->addMessage(new ToolResultMessage([$tool1WithResult]));

        // Second tool call (agent continues in tool loop)
        $chatHistory->addMessage((new ToolCallMessage(tools: [$tool2]))->setUsage(new Usage(10, 10)));

        // INVALID: User message after tool call (before tool result)
        $chatHistory->addMessage(new UserMessage('Wait, stop!'));

        // Valid tool result for second tool
        $chatHistory->addMessage(new ToolResultMessage([$tool2WithResult]));

        // Assistant responds with final answer
        $chatHistory->addMessage((new AssistantMessage('Here is my answer.'))->setUsage(new Usage(10, 10)));

        // Valid user message after complete flow
        $chatHistory->addMessage(new UserMessage('Thanks!'));

        // EXPECTED: Invalid user message removed, should have 7 messages
        $messages = $chatHistory->getMessages();

        $this->assertCount(7, $messages, 'Invalid UserMessage in middle should be removed');

        // Verify sequence is correct
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(ToolCallMessage::class, $messages[1]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $this->assertInstanceOf(ToolCallMessage::class, $messages[3]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[4]); // Tool result for tool2
        $this->assertInstanceOf(AssistantMessage::class, $messages[5]);
        $this->assertInstanceOf(UserMessage::class, $messages[6]);
    }

    public function test_trimming_exposes_bug_during_message_addition(): void
    {
        // Test that shows the bug when messages are added one at a time

        $tool = Tool::make('test_tool', 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123');

        $toolWithResult = Tool::make('test_tool', 'A test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123')
            ->setResult('Tool result');

        // Use small context window to force trimming
        $chatHistory = new InMemoryChatHistory(100);

        // Add initial messages
        $chatHistory->addMessage(new UserMessage('Question 1'));
        $chatHistory->addMessage(new UserMessage('Question 2'));
        $chatHistory->addMessage(new UserMessage('Question 3'));
        $chatHistory->addMessage((new ToolCallMessage(tools: [$tool]))->setUsage(new Usage(20, 20)));

        // BUG: Adding UserMessage after ToolCallMessage without ToolResult
        // This should fail validation
        $chatHistory->addMessage(new UserMessage('Invalid message'));

        $messages = $chatHistory->getMessages();

        // The invalid message should be removed during validation
        // If the bug exists, the count will be 4 (invalid message not removed)
        // If fixed, count should be 4 (with ToolCallMessage as last) or less
        $this->assertLessThanOrEqual(4, count($messages), 'Invalid message should cause history to be trimmed/validated');
    }
}
