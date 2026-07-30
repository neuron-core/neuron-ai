<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Chat\Messages\Message;

class PreProcessing extends ObservabilityEvent
{
    public function __construct(
        public string $processor,
        public Message $original
    ) {
    }

    public function name(): string
    {
        return 'rag-preprocessing';
    }
}
