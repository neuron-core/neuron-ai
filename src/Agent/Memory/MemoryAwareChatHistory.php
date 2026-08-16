<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Memory;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ChatHistoryException;

/**
 * Internal decorator that keeps explicit conversation deletion consistent
 * across chat history and long-term memory.
 */
final class MemoryAwareChatHistory implements ChatHistoryInterface
{
    public function __construct(
        protected ChatHistoryInterface $history,
        protected MemoryInterface $memory,
    ) {
    }

    public function setThreadId(string $threadId): void
    {
        $this->history->setThreadId($threadId);
    }

    public function getThreadId(): ?string
    {
        return $this->history->getThreadId();
    }

    public function addMessage(Message $message): ChatHistoryInterface
    {
        $this->history->addMessage($message);

        return $this;
    }

    public function getMessages(): array
    {
        return $this->history->getMessages();
    }

    public function getLastMessage(): Message|false
    {
        return $this->history->getLastMessage();
    }

    public function flushAll(): ChatHistoryInterface
    {
        $threadId = $this->getThreadId() ?? throw new ChatHistoryException(
            'Cannot clear memory for an unbound chat history.'
        );

        // Forget first: an unavailable memory store must not leave recalled
        // content behind while the chat history appears to be deleted.
        $this->memory->forget($threadId);
        $this->history->flushAll();

        return $this;
    }

    public function calculateTotalUsage(): int
    {
        return $this->history->calculateTotalUsage();
    }

    public function jsonSerialize(): array
    {
        return $this->history->jsonSerialize();
    }
}
