<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

class AgentStartEvent implements Event
{
    /**
     * @var Message[]
     */
    protected array $messages = [];

    public function setMessages(Message ...$messages): void
    {
        $this->messages = $messages;
    }

    /**
     * @return Message[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
