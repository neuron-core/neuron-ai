<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History\Stub;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;

/**
 * Exposes the protected deserialization machinery for round-trip testing.
 */
class TestableChatHistory extends AbstractChatHistory
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @return Message[]
     */
    public function publicDeserialize(array $messages): array
    {
        return $this->deserializeMessages($messages);
    }

    public function getThreadId(): string
    {
        return 'testable';
    }
}
