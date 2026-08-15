<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Retrieval;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

interface RetrievalInterface
{
    /**
     * Retrieve relevant documents for the given query.
     *
     * Incoming filters are constraints injected for this run (by middleware
     * or preceding nodes): the strategy must honor them by AND-ing them with
     * its own — they can narrow the search, never be dropped.
     *
     * @return Document[]
     */
    public function retrieve(Message $query, ?FilterGroup $filters = null): array;
}
