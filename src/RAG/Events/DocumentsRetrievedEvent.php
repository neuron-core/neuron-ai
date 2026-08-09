<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Events;

use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

/**
 * Event emitted after documents are retrieved from vector store.
 *
 * Triggers document post-processing (reranking, filtering, etc.).
 */
class DocumentsRetrievedEvent implements Event
{
    /**
     * @param Message $query The original query
     * @param array $documents Retrieved documents (Document[])
     * @param AgentStartEvent $startEvent The run's start event, carried through the
     *        chain so the inference intent survives to InstructionsNode
     */
    public function __construct(
        public readonly Message $query,
        public readonly array $documents,
        public readonly AgentStartEvent $startEvent
    ) {
    }
}
