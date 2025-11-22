<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class AbstractChatHistoryTest extends TestCase
{
    private InMemoryChatHistory $history;

    protected function setUp(): void
    {
        $this->history = new InMemoryChatHistory(
            contextWindow: 500,
            tokenCounter: new DummyTokenCounter()
        );
    }

    public function test_history_starting_with_tool_messages_is_normalized(): void
    {
        $tool = Tool::make('test_tool', 'Test tool')->setInputs([]);

        $this->history->addMessage(new ToolCallMessage('call', [$tool]))
            ->addMessage(new ToolCallResultMessage([$tool->setResult(['x' => 1])]))
            ->addMessage(new UserMessage('Hello'))
            ->addMessage(new AssistantMessage('Hi'));

        $messages = $this->history->getMessages();

        $this->assertCount(2, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
    }

    public function test_history_cleared_when_only_tool_messages_exist(): void
    {
        $tool = Tool::make('test_tool', 'Test tool')->setInputs([]);

        $this->history->addMessage(new ToolCallMessage('call', [$tool]));
        $this->history->addMessage(new ToolCallResultMessage([$tool]));

        $this->assertCount(0, $this->history->getMessages());
    }

    public function test_history_starting_with_tool_call_result_is_normalized(): void
    {
        $tool = Tool::make('test_tool', 'Test tool')
            ->setInputs([])
            ->setResult(['ok' => true]);

        // This simulates the DB snapshot scenario from issue #372:
        // history effectively starts with a tool_call_result entry.
        $this->history->addMessage(new ToolCallResultMessage([$tool]));
        $this->history->addMessage(new UserMessage('Hello'));
        $this->history->addMessage(new AssistantMessage('Hi'));

        $messages = $this->history->getMessages();

        $this->assertNotEmpty($messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
    }

    public function test_tool_messages_between_user_and_assistant_are_preserved(): void
    {
        $tool = Tool::make('test_tool', 'Test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123');

        $toolWithResult = Tool::make('test_tool', 'Test tool')
            ->setInputs(['param' => 'value'])
            ->setCallId('call_123')
            ->setResult(['ok' => true]);

        // Valid sequence:
        // user -> tool_call -> tool_call_result -> assistant
        $this->history->addMessage(new UserMessage('Use the tool'));
        $this->history->addMessage(new ToolCallMessage(null, [$tool]));
        $this->history->addMessage(new ToolCallResultMessage([$toolWithResult]));
        $this->history->addMessage(new AssistantMessage('Here is the result'));

        $messages = $this->history->getMessages();

        // Nothing should be dropped in the middle – only leading tool messages are trimmed.
        $this->assertCount(4, $messages);

        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(ToolCallMessage::class, $messages[1]);
        $this->assertInstanceOf(ToolCallResultMessage::class, $messages[2]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[3]);
    }

    public function test_role_alternation_is_enforced(): void
    {
        $this->history->addMessage(new UserMessage('U1'));
        $this->history->addMessage(new AssistantMessage('A1'));
        $this->history->addMessage(new AssistantMessage('A2 INVALID'));
        $this->history->addMessage(new UserMessage('U2'));

        $messages = $this->history->getMessages();

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
        $this->assertInstanceOf(UserMessage::class, $messages[2]);
    }
}
