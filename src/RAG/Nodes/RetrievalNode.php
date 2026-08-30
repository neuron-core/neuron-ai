<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Observability\Events\Retrieved;
use NeuronAI\Observability\Events\Retrieving;
use NeuronAI\RAG\Events\DocumentsRetrievedEvent;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterScope;
use NeuronAI\Workflow\Node;

use function array_values;
use function md5;

/**
 * Retrieves relevant documents from vector store.
 *
 * Uses the configured retrieval strategy to find documents matching the query.
 * Automatically deduplicates documents by content hash.
 */
class RetrievalNode extends Node
{
    public function __construct(
        protected readonly RetrievalInterface $retrieval,
        protected readonly ?FilterExpression $scope = null,
    ) {
    }

    /**
     * Retrieve and deduplicate documents.
     */
    public function __invoke(QueryPreProcessedEvent $event, AgentState $state): DocumentsRetrievedEvent
    {
        $query = $event->query;

        $filters = FilterScope::merge($this->scope, $event->getFilters())?->expression();

        $this->emit(new Retrieving($query, $filters));

        $documents = $this->retrieval->retrieve($query, $filters);

        // Remove duplicates by content hash
        $docs = [];
        foreach ($documents as $document) {
            $hash = md5($document->getContent());
            $docs[$hash] = $document;
        }
        $docs = array_values($docs);

        $this->emit(new Retrieved($query, $docs));

        return new DocumentsRetrievedEvent($query, $docs, $event->startEvent);
    }
}
