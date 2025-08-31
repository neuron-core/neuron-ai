<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Retrieval;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class SimilarityRetrieval implements RetrievalInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingsProviderInterface $embeddingProvider,
        private array $config = []
    ) {
    }

    public function retrieve(Message $query): array
    {
        return $this->vectorStore->similaritySearch(
            $this->embeddingProvider->embedText($query->getContent())
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfiguration(array $config): RetrievalInterface
    {
        $this->config = \array_merge($this->config, $config);
        return $this;
    }
}
