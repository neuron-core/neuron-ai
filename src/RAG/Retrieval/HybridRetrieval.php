<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Retrieval;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\HybridVectorStoreInterface;

class HybridRetrieval implements RetrievalInterface
{
    public function __construct(
        protected readonly HybridVectorStoreInterface $vectorStore,
        protected readonly EmbeddingsProviderInterface $embeddingProvider,
    ) {
    }

    public function retrieve(Message $query): array
    {
        $content = $query->getContent();

        return $this->vectorStore->hybridSearch(
            $content,
            $this->embeddingProvider->embedText($content),
        );
    }
}
