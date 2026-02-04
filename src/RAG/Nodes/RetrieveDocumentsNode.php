<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Observability\Events\Retrieved;
use NeuronAI\Observability\Events\Retrieving;
use NeuronAI\RAG\Events\DocumentsRetrievedEvent;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\Workflow\Node;

use function array_values;
use function md5;

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

        $this->emit('rag-retrieving', new Retrieving($query));

        $documents = $this->retrieval->retrieve($query);

        // Remove duplicates by content hash
        $docs = [];
        foreach ($documents as $document) {
            $hash = md5($document->getContent());
            $docs[$hash] = $document;
        }
        $docs = array_values($docs);

        $this->emit('rag-retrieved', new Retrieved($query, $docs));

        return new DocumentsRetrievedEvent($query, $docs);
    }
}
