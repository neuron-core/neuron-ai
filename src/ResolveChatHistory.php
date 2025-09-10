<?php

declare(strict_types=1);

namespace NeuronAI;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

trait ResolveChatHistory
{
    protected ChatHistoryInterface $chatHistory;

    /**
     * Called on the agent instance.
     */
    public function withChatHistory(ChatHistoryInterface $chatHistory): self
    {
        $this->chatHistory = $chatHistory;
        return $this;
    }

    /**
     * Used extending the Agent.
     */
    protected function chatHistory(): ChatHistoryInterface
    {
        return new InMemoryChatHistory();
    }

    public function addToChatHistory(Message|array $messages): void
    {
        $messages = \is_array($messages) ? $messages : [$messages];

        foreach ($messages as $message) {
            $this->notify('message-saving', new MessageSaving($message));
            $this->resolveChatHistory()->addMessage($message);
            $this->notify('message-saved', new MessageSaved($message));
        }
    }

    /**
     * Get the current instance of the chat history.
     */
    public function resolveChatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory ?? $this->chatHistory = $this->chatHistory();
    }
}
