<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Observability\Events\Retrieving;
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
     *
     * @throws InspectorException
     */
    public function __invoke(QueryPreProcessedEvent $event, AgentState $state): DocumentsRetrievedEvent
    {
        $query = $event->query;

        $this->emit('rag-retrieving', new Retrieving($query));

        $documents = $this->retrieval->retrieve($query);

        // Deduplicate documents by content hash
        $retrievedDocs = [];
        foreach ($documents as $document) {
            $hash = \md5($document->getContent());
            $retrievedDocs[$hash] = $document;
        }
        $retrievedDocs = \array_values($retrievedDocs);

        $this->emit('rag-retrieved', new Retrieving($query));

        return new DocumentsRetrievedEvent($query, $retrievedDocs);
    }
}
