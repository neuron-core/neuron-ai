<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

use function is_array;
use function spl_object_hash;

trait ChatHistoryHelper
{
    /**
     * @throws InspectorException
     */
    protected function addToChatHistory(AgentState $state, Message|array $messages): void
    {
        $messages = is_array($messages) ? $messages : [$messages];

        foreach ($messages as $message) {
            EventBus::emit('message-saving', $this, new MessageSaving($message));
            $state->getChatHistory()->addMessage($message);
            EventBus::emit('message-saved', $this, new MessageSaved($message));
        }
    }

    /**
     * Adds the given messages to the chat history exactly once per node instance.
     * Uses a per-instance key in the AgentState to avoid duplicate additions when a node runs multiple times.
     *
     * @param Message[] $messages
     * @throws InspectorException
     */
    protected function addInitialMessagesOnce(AgentState $state, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $initKey = 'chat_init_' . spl_object_hash($this);
        if (!$state->get($initKey, false)) {
            $this->addToChatHistory($state, $messages);
            $state->set($initKey, true);
        }
    }
}
