<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Observability;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;

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

    public function toArray(): array
    {
        return [
            'processor' => $this->processor,
            'original' => $this->original->jsonSerialize(),
        ];
    }
}
