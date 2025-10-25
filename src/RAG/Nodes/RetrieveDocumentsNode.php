<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\RAG\Events\DocumentsRetrievedEvent;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\Workflow\Node;

/**
 * Retrieves relevant documents from vector store.
 *
 * Uses the configured retrieval strategy to find documents matching the query.
 * Automatically deduplicates documents by content hash.
 */
class RetrieveDocumentsNode extends Node
{
    public function __construct(
        private readonly RetrievalInterface $retrieval
    ) {
    }

    /**
     * Retrieve and deduplicate documents.
     */
    public function __invoke(QueryPreProcessedEvent $event, AgentState $state): DocumentsRetrievedEvent
    {
        $query = $event->query;

        $documents = $this->retrieval->retrieve($query);

        // Deduplicate documents by content hash
        $retrievedDocs = [];
        foreach ($documents as $document) {
            $hash = \md5($document->getContent());
            $retrievedDocs[$hash] = $document;
        }
        $retrievedDocs = \array_values($retrievedDocs);

        return new DocumentsRetrievedEvent($query, $retrievedDocs);
    }
}
