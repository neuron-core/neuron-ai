<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Observability;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\RAG\Document;

class PostProcessing extends ObservabilityEvent
{
    /**
     * @param Document[] $documents
     */
    public function __construct(
        public string $processor,
        public Message $question,
        public array $documents
    ) {
    }

    public function name(): string
    {
        return 'rag-postprocessing';
    }

    public function toArray(): array
    {
        return [
            'processor' => $this->processor,
            'question' => $this->question->jsonSerialize(),
            'documents' => $this->documents,
        ];
    }
}
