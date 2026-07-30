<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Chat\Messages\Message;

class Retrieving extends ObservabilityEvent
{
    public function __construct(
        public Message $question
    ) {
    }

    public function name(): string
    {
        return 'rag-retrieving';
    }
}
