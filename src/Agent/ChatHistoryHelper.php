<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

use function is_array;

/**
 * Holds the chat history reference for agent nodes and centralizes writes.
 *
 * The history is a live service bound to its own storage — writes are durable
 * immediately. On crash-replay a node re-adds the same messages against the
 * live history; the replace-last convergence rules in AbstractChatHistory make
 * the re-add idempotent.
 */
trait ChatHistoryHelper
{
    protected ChatHistoryInterface $chatHistory;

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory;
    }

    /**
     * @throws InspectorException
     */
    protected function addToChatHistory(Message|array $messages): void
    {
        $messages = is_array($messages) ? $messages : [$messages];

        foreach ($messages as $message) {
            $this->emit(new MessageSaving($message));
            $this->chatHistory->addMessage($message);
            $this->emit(new MessageSaved($message));
        }
    }
}
