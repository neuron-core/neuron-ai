<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\TokenCounterInterface;
use NeuronAI\Chat\Messages\Message;

class DummyTokenCounter implements TokenCounterInterface
{
    /**
     * @param array<int, Message> $messages
     */
    public function count(array $messages): int
    {
        // 10 "tokens" per message, arbitrary but stable
        return \count($messages) * 10;
    }
}
