<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

trait ChatHistoryHelper
{
    /**
     * @throws InspectorException
     */
    protected function addToChatHistory(AgentState $state, Message $message): void
    {
        $this->emit('message-saving', new MessageSaving($message));
        $state->getChatHistory()->addMessage($message);
        $this->emit('message-saved', new MessageSaved($message));
    }
}
