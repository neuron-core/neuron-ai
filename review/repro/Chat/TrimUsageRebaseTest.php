<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use PHPUnit\Framework\TestCase;

class TrimUsageRebaseTest extends TestCase
{
    public function test_rebasing_a_kept_checkpoint_keeps_its_cache_and_reasoning_counts(): void
    {
        $messages = [
            new UserMessage('q1'),
            (new AssistantMessage('a1'))->setUsage(new Usage(100, 50)),
            new UserMessage('q2'),
            (new AssistantMessage('a2'))->setUsage(new Usage(300, 50, 200, 20)),
        ];

        $trimmed = (new HistoryTrimmer())->trim($messages, 300);

        $this->assertCount(2, $trimmed);
        $this->assertSame(
            ['input_tokens' => 150, 'output_tokens' => 50, 'cached_input_tokens' => 200, 'reasoning_tokens' => 20],
            $trimmed[1]->getUsage()?->jsonSerialize()
        );
    }

    public function test_the_stored_answer_keeps_its_cache_and_reasoning_counts_after_a_trim(): void
    {
        $store = new SqliteMessageStore();
        $history = new ChatHistory($store, 'thread', 300);
        $history->addMessage(new UserMessage('q1'));
        $history->addMessage((new AssistantMessage('a1'))->setUsage(new Usage(100, 50)));
        $history->addMessage(new UserMessage('q2'));

        $history->addMessage((new AssistantMessage('a2'))->setUsage(new Usage(300, 50, 200, 20)));

        $this->assertCount(2, $store->loadActive('thread'));
        $stored = $store->loadAll('thread')[3]->getUsage();
        $this->assertSame(200, $stored?->cachedInputTokens);
        $this->assertSame(20, $stored?->reasoningTokens);
    }
}
