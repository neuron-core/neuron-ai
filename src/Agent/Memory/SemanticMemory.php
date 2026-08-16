<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Memory;

use NeuronAI\Exceptions\AgentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * Vector-backed conversation memory using portable source filters.
 */
class SemanticMemory implements MemoryInterface
{
    public const SOURCE_TYPE = 'agent-memory';

    public function __construct(
        protected VectorStoreInterface $vectorStore,
        protected EmbeddingsProviderInterface $embeddings,
        protected int $topK = 5,
    ) {
        if ($this->topK < 1) {
            throw new AgentException('Semantic memory topK must be greater than zero.');
        }
    }

    /**
     * @param non-empty-list<string> $threadIds
     */
    public function recall(array $threadIds, string $query): array
    {
        $documents = $this->vectorStore->search(new SearchRequest(
            embedding: $this->embeddings->embedText($query),
            filters: $this->recallFilters($threadIds),
            topK: $this->topK,
        ));

        $memories = [];

        foreach ($documents as $document) {
            $memories[] = $document->getContent();
        }

        return $memories;
    }

    public function remember(string $threadId, string $user, string $assistant): void
    {
        $document = (new Document($this->formatExchange($user, $assistant)))
            ->setSourceType(static::SOURCE_TYPE)
            ->setSourceName($threadId);

        $this->vectorStore->addDocument(
            $this->embeddings->embedDocument($document)
        );
    }

    public function forget(string $threadId): void
    {
        $this->vectorStore->delete($this->threadFilters($threadId));
    }

    protected function threadFilters(string $threadId): FilterGroup
    {
        return FilterGroup::and(
            Filter::eq('sourceType', static::SOURCE_TYPE),
            Filter::eq('sourceName', $threadId),
        );
    }

    /**
     * @param non-empty-list<string> $threadIds
     */
    protected function recallFilters(array $threadIds): FilterGroup
    {
        return FilterGroup::and(
            Filter::eq('sourceType', static::SOURCE_TYPE),
            Filter::in('sourceName', $threadIds),
        );
    }

    protected function formatExchange(string $user, string $assistant): string
    {
        return "User: {$user}\nAssistant: {$assistant}";
    }
}
