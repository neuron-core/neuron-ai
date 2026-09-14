<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\SearchRequest;

class VectorStoreRecord
{
    /**
     * @param string $method The method called: 'addDocument', 'addDocuments', 'delete' or 'search'
     * @param Document[] $documents The documents stored (add methods only)
     * @param FilterExpression|null $filters The filters applied (delete only)
     * @param SearchRequest|null $request The search input (search only)
     */
    public function __construct(
        public readonly string $method,
        public readonly array $documents = [],
        public readonly ?FilterExpression $filters = null,
        public readonly ?SearchRequest $request = null,
    ) {
    }
}
