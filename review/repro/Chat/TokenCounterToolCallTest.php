<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class TokenCounterToolCallTest extends TestCase
{
    public function test_tool_call_arguments_count_toward_the_context(): void
    {
        $message = new ToolCallMessage(null, [new ToolCall('write_file', 'call-1', ['content' => str_repeat('a', 40000)])]);

        $this->assertGreaterThanOrEqual(10000, (new TokenCounter())->count($message));
    }

    public function test_trimmer_drops_old_turns_when_tool_call_arguments_overflow_the_window(): void
    {
        $messages = [
            new UserMessage('first question'),
            new AssistantMessage('first answer'),
            new UserMessage('write the file'),
            new ToolCallMessage(null, [new ToolCall('write_file', 'call-1', ['content' => str_repeat('a', 40000)])]),
            new ToolResultMessage([(new ToolCall('write_file', 'call-1'))->setResult('ok')]),
        ];

        $trimmer = new HistoryTrimmer();
        $trimmer->trim($messages, 5000);

        $this->assertGreaterThan(5000, $trimmer->getTotalTokens());
    }
}
