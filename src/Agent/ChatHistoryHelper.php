<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

trait ChatHistoryHelper
{
    /**
     * @throws InspectorException
     */
    protected function addToChatHistory(AgentState $state, Message $message): void
    {
        EventBus::emit('message-saving', $this, new MessageSaving($message));
        $state->getChatHistory()->addMessage($message);
        EventBus::emit('message-saved', $this, new MessageSaved($message));
    }
}
