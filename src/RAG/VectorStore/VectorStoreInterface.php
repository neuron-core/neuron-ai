<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

interface VectorStoreInterface
{
    public function getSchema(): DocumentSchema;

    public function addDocument(Document $document): VectorStoreInterface;

    /**
     * @param  Document[]  $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface;

    /**
     * Delete every document matching the filters.
     */
    public function delete(FilterExpression $filters): VectorStoreInterface;

    /**
     * Return the documents most similar to the request's embedding.
     *
     * @return iterable<Document>
     */
    public function search(SearchRequest $request): iterable;
}
