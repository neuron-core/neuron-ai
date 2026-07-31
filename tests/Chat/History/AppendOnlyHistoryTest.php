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

class AppendOnlyHistoryTest extends TestCase
{
    private function toolWithState(string $name, string $callId, ?ApprovalState $state = null): Tool
    {
        $tool = ToolDefinition::make($name, "desc {$name}")
            ->setInputs([])
            ->setCallId($callId);

        if ($state instanceof ApprovalState) {
            $tool->setApprovalState($state);
        }

        return $tool;
    }

    public function test_add_message_always_appends_even_for_matching_tool_call(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('go'));

        $first = new ToolCallMessage(tools: [
            $this->toolWithState('a', 'c1'),
            $this->toolWithState('b', 'c2'),
        ]);
        $history->addMessage($first);

        // ADR 0006: the history is append-only — a matching ToolCallMessage is the
        // caller's responsibility to dedup (ToolNode / ToolApproval tail guards).
        $duplicate = new ToolCallMessage(tools: [
            $this->toolWithState('a', 'c1', ApprovalState::Approved),
            $this->toolWithState('b', 'c2', ApprovalState::Rejected),
        ]);
        $history->addMessage($duplicate);

        $this->assertCount(3, $history->getMessages());
        $this->assertSame($first, $history->getMessages()[1]);
        $this->assertSame($duplicate, $history->getMessages()[2]);
    }

    public function test_is_same_tool_call_matches_ordered_call_ids(): void
    {
        $message = new ToolCallMessage(tools: [
            $this->toolWithState('a', 'c1'),
            $this->toolWithState('b', 'c2'),
        ]);

        $sameIds = new ToolCallMessage(tools: [
            $this->toolWithState('a', 'c1', ApprovalState::Approved),
            $this->toolWithState('b', 'c2'),
        ]);
        $this->assertTrue($message->isSameToolCall($sameIds));

        $differentIds = new ToolCallMessage(tools: [$this->toolWithState('b', 'c3')]);
        $this->assertFalse($message->isSameToolCall($differentIds));

        $reordered = new ToolCallMessage(tools: [
            $this->toolWithState('b', 'c2'),
            $this->toolWithState('a', 'c1'),
        ]);
        $this->assertFalse($message->isSameToolCall($reordered));

        $this->assertFalse($message->isSameToolCall(new UserMessage('not a tool call')));
        $this->assertFalse($message->isSameToolCall(null));
    }
}
