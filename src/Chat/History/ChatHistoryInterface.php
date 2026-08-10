<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;
use JsonSerializable;

interface ChatHistoryInterface extends JsonSerializable
{
    /**
     * The conversation's identity — the single source of truth the Agent reads
     * back (ignition record, channel wiring) instead of holding its own copy.
     * Non-nullable: every history has an identity (an identity-less backend
     * generates one, e.g. InMemoryChatHistory).
     */
    public function getThreadId(): string;

    public function addMessage(Message $message): ChatHistoryInterface;

    /**
     * @return Message[]
     */
    public function getMessages(): array;

    public function getLastMessage(): Message|false;

    public function flushAll(): ChatHistoryInterface;

    public function calculateTotalUsage(): int;
}
