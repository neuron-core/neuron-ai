<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

class Retrieving extends ObservabilityEvent
{
    public function __construct(
        public Message $question,
        public ?FilterExpression $filters = null,
    ) {
    }

    public function name(): string
    {
        return 'rag-retrieving';
    }
}
