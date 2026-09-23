<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

class AppendOnlyHistoryTest extends TestCase
{
    private function toolWithState(string $name, string $callId, ?ApprovalState $state = null): ToolCall
    {
        $tool = ToolCall::make($name, $callId, [], "desc {$name}");

        if ($state instanceof ApprovalState) {
            $tool->setApprovalState($state);
        }

        return $tool;
    }

    public function test_add_message_always_appends_even_for_matching_tool_call(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $history->addMessage(new UserMessage('go'));

        $first = new ToolCallMessage(tools: [
            $this->toolWithState('a', 'c1'),
            $this->toolWithState('b', 'c2'),
        ]);
        $history->addMessage($first);

        // The history is append-only and skips only a message it already
        // holds: another message carrying the same calls still appends.
        $duplicate = new ToolCallMessage(tools: [
            $this->toolWithState('a', 'c1', ApprovalState::Approved),
            $this->toolWithState('b', 'c2', ApprovalState::Rejected),
        ]);
        $history->addMessage($duplicate);

        $this->assertCount(3, $history->getMessages());
        $this->assertSame($first, $history->getMessages()[1]);
        $this->assertSame($duplicate, $history->getMessages()[2]);
    }

}
