<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

/**
 * Event emitted after documents are post-processed.
 *
 * Triggers instruction enrichment with document context.
 */
class DocumentsProcessedEvent extends Event
{
    /**
     * @param Message $query The original query
     * @param array $documents Processed documents (Document[])
     */
    public function __construct(
        public readonly Message $query,
        public readonly array $documents
    ) {
    }
}
