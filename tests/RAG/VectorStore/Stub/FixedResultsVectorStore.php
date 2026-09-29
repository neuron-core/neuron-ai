<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Stub;

use Closure;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * A store whose searches return what the given closure produces, arrays or generators alike.
 */
class FixedResultsVectorStore implements VectorStoreInterface
{
    /**
     * @param Closure(): iterable<Document> $results
     */
    public function __construct(protected Closure $results)
    {
    }

    public function getSchema(): DocumentSchema
    {
        return DocumentSchema::default();
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this;
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        return $this;
    }

    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        return $this;
    }

    public function search(SearchRequest $request): iterable
    {
        return ($this->results)();
    }
}
