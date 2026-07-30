<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Chat\Messages\Message;

class PreProcessed extends ObservabilityEvent
{
    public function __construct(
        public string $processor,
        public Message $processed
    ) {
    }

    public function name(): string
    {
        return 'rag-preprocessed';
    }
}
