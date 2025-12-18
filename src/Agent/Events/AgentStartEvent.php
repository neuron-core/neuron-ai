<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

class AgentStartEvent implements Event
{
    protected Message|array $messages = [];

    public function setMessages(Message|array $messages): void
    {
        $this->messages = $messages;
    }

    public function getMessages(): Message|array
    {
        return $this->messages;
    }
}
