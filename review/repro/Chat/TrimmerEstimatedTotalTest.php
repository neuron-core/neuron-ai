<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MAX;

class TrimmerEstimatedTotalTest extends TestCase
{
    public function test_the_total_after_an_estimated_trim_counts_the_kept_messages_only(): void
    {
        $counter = new TokenCounter();
        $messages = [new UserMessage('Hello'), new AssistantMessage('Hello'), new UserMessage('Hello'), new AssistantMessage('Hello')];
        $keptTokens = $counter->count($messages[2]) + $counter->count($messages[3]);
        $fullTokens = $keptTokens + $counter->count($messages[0]) + $counter->count($messages[1]);

        $trimmer = new HistoryTrimmer($counter);
        $kept = $trimmer->trim($messages, $keptTokens + 1);

        $this->assertSame([$messages[2], $messages[3]], $kept);
        $this->assertSame($keptTokens, $trimmer->getTotalTokens());
        $this->assertLessThan($fullTokens, $trimmer->getTotalTokens());
    }

    public function test_the_total_after_an_estimated_trim_matches_a_fresh_measurement(): void
    {
        $messages = [new UserMessage('Hello'), new AssistantMessage('Hello'), new UserMessage('Hello'), new AssistantMessage('Hello')];

        $trimmer = new HistoryTrimmer();
        $kept = $trimmer->trim($messages, 30);

        $measurer = new HistoryTrimmer();
        $measurer->trim($kept, PHP_INT_MAX);

        $this->assertSame($measurer->getTotalTokens(), $trimmer->getTotalTokens());
    }
}
