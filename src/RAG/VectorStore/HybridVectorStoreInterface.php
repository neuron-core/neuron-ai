<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\RAG\Document;

interface HybridVectorStoreInterface extends VectorStoreInterface
{
    /**
     * @param  float[]  $embedding
     * @return Document[]
     */
    public function hybridSearch(string $query, array $embedding): array;
}
