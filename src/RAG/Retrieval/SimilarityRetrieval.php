<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Retrieval;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class SimilarityRetrieval implements RetrievalInterface
{
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingsProviderInterface $embeddingProvider,
    ) {
    }

    public function retrieve(Message $query): array
    {
        return $this->vectorStore->similaritySearch(
            $this->embeddingProvider->embedText($query->getContent())
        );
    }
}
