<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;

trait ResolveRetrieval
{
    protected RetrievalInterface $retrieval;

    public function setRetrieval(RetrievalInterface $retrieval): RAG
    {
        $this->retrieval = $retrieval;
        return $this;
    }

    /**
     * Provide the default retrieval strategy.
     */
    protected function retrieval(): RetrievalInterface
    {
        return new SimilarityRetrieval(
            $this->resolveVectorStore(),
            $this->resolveEmbeddingsProvider()
        );
    }

    public function resolveRetrieval(): RetrievalInterface
    {
        return $this->retrieval ?? $this->retrieval = $this->retrieval();
    }
}
