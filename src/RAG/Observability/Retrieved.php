<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Observability;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\RAG\Document;

class Retrieved extends ObservabilityEvent
{
    /**
     * @param Document[] $documents
     */
    public function __construct(
        public Message $question,
        public array $documents,
    ) {
    }

    public function name(): string
    {
        return 'rag-retrieved';
    }

    public function toArray(): array
    {
        return [
            'question' => $this->question->jsonSerialize(),
            'documents' => $this->documents,
        ];
    }
}
