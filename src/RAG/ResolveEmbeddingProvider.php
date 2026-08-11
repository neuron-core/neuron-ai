<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\Exceptions\AgentException;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

trait ResolveEmbeddingProvider
{
    protected EmbeddingsProviderInterface $embeddingsProvider;

    public function setEmbeddingsProvider(EmbeddingsProviderInterface $provider): RAG
    {
        $this->embeddingsProvider = $provider;
        return $this;
    }

    /**
     * Provide the default embeddings provider. Subclasses override this hook —
     * never resolveEmbeddingsProvider(), which memoizes the resolved instance.
     *
     * @throws AgentException
     */
    protected function embeddings(): EmbeddingsProviderInterface
    {
        throw new AgentException(
            'No embeddings provider configured: override the embeddings() method in your RAG agent, or call setEmbeddingsProvider().'
        );
    }

    final public function resolveEmbeddingsProvider(): EmbeddingsProviderInterface
    {
        return $this->embeddingsProvider ??= $this->embeddings();
    }
}
