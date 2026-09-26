<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

class TrimBackwardMiscountTest extends TestCase
{
    public function test_a_backward_cut_subtracts_the_tokens_of_the_dropped_turn_only(): void
    {
        $call = new ToolCall('lookup', 'call-1', ['q' => 'x']);
        $messages = [
            new UserMessage('first'),
            (new AssistantMessage('ok'))->setUsage(new Usage(10, 5)),
            new UserMessage('second'),
            (new ToolCallMessage(null, [$call]))->setUsage(new Usage(60, 10)),
            new ToolResultMessage([(clone $call)->setResult('found')]),
            (new AssistantMessage('found it'))->setUsage(new Usage(100, 20)),
            new UserMessage('third'),
            (new AssistantMessage('done'))->setUsage(new Usage(150, 20)),
        ];
        $trimmer = new HistoryTrimmer();

        $trimmed = $trimmer->trim($messages, 150);

        // Only [first, ok] (15 tokens) is dropped: 155 of 170 tokens remain.
        $this->assertCount(6, $trimmed);
        $this->assertSame($messages[2], $trimmed[0]);
        $this->assertSame(155, $trimmer->getTotalTokens());
        $this->assertSame(45, $trimmed[1]->getUsage()->inputTokens);
        $this->assertSame(135, $trimmed[5]->getUsage()->inputTokens);
    }
}
