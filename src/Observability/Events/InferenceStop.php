<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;

class InferenceStop
{
    public function __construct(
        public Message|false $message,
        public ProviderResponse $response
    ) {
    }
}
