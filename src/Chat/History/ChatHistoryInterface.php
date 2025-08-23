<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;

interface ChatHistoryInterface extends \JsonSerializable
{
    public function addMessage(Message $message): ChatHistoryInterface;

    /**
     * @return Message[]
     */
    public function getMessages(): array;

    public function getLastMessage(): Message|false;

    /**
     * @param Message[] $messages
     */
    public function setMessages(array $messages): ChatHistoryInterface;

    public function flushAll(): ChatHistoryInterface;

    public function calculateTotalUsage(): int;
}
