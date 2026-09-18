<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Retrieval;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

use function array_unique;
use function array_values;
use function is_string;

class SemanticMemoryRetrieval extends SimilarityRetrieval
{
    public const SOURCE_TYPE = 'conversation';

    /** @param non-empty-list<string> $threadIds */
    public function __construct(
        VectorStoreInterface $vectorStore,
        EmbeddingsProviderInterface $embeddingProvider,
        array $threadIds,
    ) {
        if ($threadIds === []) {
            throw new VectorStoreException('Semantic memory retrieval requires at least one thread ID.');
        }

        foreach ($threadIds as $threadId) {
            if (!is_string($threadId) || $threadId === '') {
                throw new VectorStoreException('Semantic memory thread IDs must be non-empty strings.');
            }
        }

        parent::__construct($vectorStore, $embeddingProvider, FilterGroup::and(
            Filter::eq('sourceType', self::SOURCE_TYPE),
            Filter::in('sourceName', array_values(array_unique($threadIds))),
        ));
    }
}
