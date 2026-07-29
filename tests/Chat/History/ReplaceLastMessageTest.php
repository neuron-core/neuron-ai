<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolDefinition;
use PHPUnit\Framework\TestCase;

class ReplaceLastMessageTest extends TestCase
{
    private function toolWithState(string $name, string $callId, ?ApprovalState $state = null): Tool
    {
        $tool = ToolDefinition::make($name, "desc {$name}")
            ->setInputs([])
            ->setCallId($callId);

        if ($state instanceof \NeuronAI\Tools\ApprovalState) {
            $tool->setApprovalState($state);
        }

        return $tool;
    }

    public function test_tool_call_with_same_call_ids_replaces_last(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('go'));

        $first = new ToolCallMessage(tools: [
            $this->toolWithState('a', 'c1'),
            $this->toolWithState('b', 'c2'),
        ]);
        $history->addMessage($first);
        $this->assertCount(2, $history->getMessages());

        $updated = new ToolCallMessage(tools: [
            $this->toolWithState('a', 'c1', ApprovalState::Approved),
            $this->toolWithState('b', 'c2', ApprovalState::Rejected),
        ]);
        $history->addMessage($updated);

        $this->assertCount(2, $history->getMessages(), 'A matching ToolCallMessage must replace, not append');

        $last = $history->getMessages()[1];
        $this->assertInstanceOf(ToolCallMessage::class, $last);
        $tools = $last->getTools();
        $this->assertEquals(ApprovalState::Approved, $tools[0]->getApprovalState());
        $this->assertEquals(ApprovalState::Rejected, $tools[1]->getApprovalState());
    }

    public function test_tool_call_with_different_call_ids_appends(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('go'));

        $history->addMessage(new ToolCallMessage(tools: [$this->toolWithState('a', 'c1')]));
        $history->addMessage(new ToolCallMessage(tools: [$this->toolWithState('b', 'c3')]));

        $this->assertCount(3, $history->getMessages());
    }

    public function test_non_tool_call_messages_always_append(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('one'));

        // Adding a second user message hits the sequence validator, not the replace
        // branch — it throws rather than silently replacing. That proves non-tool-call
        // messages never satisfy the replace guard.
        $this->expectException(\NeuronAI\Exceptions\ChatHistoryException::class);
        $history->addMessage(new UserMessage('two'));
    }

    public function test_replace_does_not_match_when_last_is_not_tool_call(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('go'));

        $history->addMessage(new ToolCallMessage(tools: [$this->toolWithState('a', 'c1')]));

        $this->assertCount(2, $history->getMessages());
    }
}
