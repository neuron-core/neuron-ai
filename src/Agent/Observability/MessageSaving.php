<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;

class MessageSaving extends ObservabilityEvent
{
    public function __construct(public Message $message)
    {
    }

    public function toArray(): array
    {
        return ['message' => $this->message->jsonSerialize()];
    }
}
