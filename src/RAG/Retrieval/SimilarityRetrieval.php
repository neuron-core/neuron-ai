<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Retrieval;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

use function is_array;
use function iterator_to_array;

class SimilarityRetrieval implements RetrievalInterface
{
    public function __construct(
        protected readonly VectorStoreInterface $vectorStore,
        protected readonly EmbeddingsProviderInterface $embeddingProvider,
        protected readonly ?FilterGroup $filters = null,
    ) {
    }

    public function retrieve(Message $query, ?FilterGroup $filters = null): array
    {
        $documents = $this->vectorStore->search(new SearchRequest(
            embedding: $this->embeddingProvider->embedText($query->getContent()),
            // Filters only accumulate: incoming per-run constraints are AND-ed
            // with the strategy's own, so neither can widen what the other scoped.
            filters: FilterGroup::merge($this->filters, $filters),
        ));

        return is_array($documents) ? $documents : iterator_to_array($documents);
    }
}
