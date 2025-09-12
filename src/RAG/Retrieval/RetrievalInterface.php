<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Retrieval;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;

interface RetrievalInterface
{
    /**
     * Retrieve relevant documents for the given query.
     *
     * @return Document[]
     */
    public function retrieve(Message $query): array;
}
