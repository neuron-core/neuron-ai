<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Retrieval;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

class CompositeRetrieval implements RetrievalInterface
{
    /** @param RetrievalInterface[] $retrievals */
    public function __construct(protected readonly array $retrievals)
    {
    }

    /** @return Document[] */
    public function retrieve(Message $query, ?FilterExpression $filters = null): array
    {
        $documents = [];

        foreach ($this->retrievals as $retrieval) {
            foreach ($retrieval->retrieve($query, $filters) as $document) {
                $documents[] = $document;
            }
        }

        return $documents;
    }
}
