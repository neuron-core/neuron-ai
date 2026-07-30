<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Chat\Messages\Message;
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
}
