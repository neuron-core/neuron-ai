<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

trait ResolveRetrieval
{
    protected RetrievalInterface $retrieval;

    protected ?FilterExpression $configuredRetrievalScope = null;

    public function setRetrieval(RetrievalInterface $retrieval): RAG
    {
        $this->retrieval = $retrieval;
        return $this;
    }

    public function setRetrievalScope(?FilterExpression $scope): RAG
    {
        $this->configuredRetrievalScope = $scope;
        return $this;
    }

    protected function retrievalScope(): ?FilterExpression
    {
        return null;
    }

    final public function resolveRetrievalScope(): ?FilterExpression
    {
        return $this->configuredRetrievalScope ?? $this->retrievalScope();
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

    final public function resolveRetrieval(): RetrievalInterface
    {
        return $this->retrieval ??= $this->retrieval();
    }
}
