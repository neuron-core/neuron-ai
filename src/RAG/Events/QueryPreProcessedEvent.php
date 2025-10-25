<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

/**
 * Event emitted after query preprocessing.
 *
 * Triggers document retrieval from vector store.
 */
class QueryPreProcessedEvent implements Event
{
    public function __construct(
        public readonly Message $query
    ) {
    }
}
