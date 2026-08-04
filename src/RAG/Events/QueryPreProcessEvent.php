<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

/**
 * Event that triggers RAG preprocessing.
 *
 * Emitted by PrepareRAGNode to initiate the RAG pipeline.
 */
class QueryPreProcessEvent implements Event
{
    public function __construct(
        public readonly Message $query
    ) {
    }
}
