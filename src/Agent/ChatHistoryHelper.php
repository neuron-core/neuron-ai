<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

use function is_array;

trait ChatHistoryHelper
{
    protected function addToChatHistory(AgentState $state, Message|array $messages): void
    {
        $messages = is_array($messages) ? $messages : [$messages];

        foreach ($messages as $message) {
            $this->emit('message-saving', new MessageSaving($message));
            $state->getChatHistory()->addMessage($message);
            $this->emit('message-saved', new MessageSaved($message));
        }
    }
}
