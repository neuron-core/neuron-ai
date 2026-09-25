<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Providers\ProviderResponse;

class InferenceStop extends ObservabilityEvent
{
    public function __construct(
        public Message|false $message,
        public ProviderResponse $response
    ) {
    }

    public function toArray(): array
    {
        return [
            'message' => $this->message->jsonSerialize(),
            'response' => $this->response->message()->jsonSerialize(),
        ];
    }
}
