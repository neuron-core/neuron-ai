<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class TokenCounterInvalidUtf8Test extends TestCase
{
    public function test_invalid_utf8_is_counted_like_its_substituted_form(): void
    {
        $counter = new TokenCounter();

        $this->assertSame(
            $counter->count(new UserMessage("binary \u{FFFD}\u{FFFD} payload")),
            $counter->count(new UserMessage("binary \xff\xfe payload"))
        );
    }

    public function test_chat_history_accepts_a_message_with_invalid_utf8(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');

        $history->addMessage(new UserMessage("truncated multibyte \xe2\x82"));

        $this->assertCount(1, $history->getMessages());
    }
}
