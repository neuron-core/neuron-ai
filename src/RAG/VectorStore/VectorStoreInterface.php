<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\RAG\Document;

interface VectorStoreInterface
{
    public function addDocument(Document $document): VectorStoreInterface;

    /**
     * @param  Document[]  $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface;

    /**
     * @deprecated Use deleteBy() instead.
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface;

    // todo uncomment in v4
    // public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface;

    /**
     * Return docs most similar to the embedding.
     *
     * @param  float[]  $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): iterable;
}
