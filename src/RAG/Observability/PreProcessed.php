<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Observability;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;

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

    public function toArray(): array
    {
        return [
            'processor' => $this->processor,
            'processed' => $this->processed->jsonSerialize(),
        ];
    }
}
