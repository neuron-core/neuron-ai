<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

class ToolCallFirstTest extends TestCase
{
    public function test_a_history_cannot_start_with_a_plain_assistant_message(): void
    {
        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('position 0');

        (new HistoryTrimmer())->trim([new AssistantMessage('hi')], 100000);
    }

    public function test_a_history_cannot_start_with_a_tool_call(): void
    {
        $call = new ToolCall('lookup', 'call-1');
        $messages = [new ToolCallMessage(null, [$call]), new ToolResultMessage([(clone $call)->setResult('found')])];

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('position 0');

        (new HistoryTrimmer())->trim($messages, 100000);
    }
}
