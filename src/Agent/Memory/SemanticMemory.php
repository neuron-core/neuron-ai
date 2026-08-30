<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Memory;

use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

use function array_unique;
use function array_values;
use function is_string;

/**
 * Vector-backed conversation memory using portable source filters.
 */
class SemanticMemory implements MemoryInterface
{
    public const SOURCE_TYPE = 'agent-memory';

    /** @var non-empty-list<string> */
    protected array $recallThreadIds;

    /**
     * @param string[] $recallThreadIds
     * @throws AgentException
     */
    public function __construct(
        protected VectorStoreInterface $vectorStore,
        protected EmbeddingsProviderInterface $embeddings,
        array $recallThreadIds,
        protected int $topK = 5,
    ) {
        if ($recallThreadIds === []) {
            throw new AgentException('Semantic memory recall requires at least one thread ID.');
        }

        foreach ($recallThreadIds as $threadId) {
            if (!is_string($threadId) || $threadId === '') {
                throw new AgentException('Semantic memory recall thread IDs must be non-empty strings.');
            }
        }

        $this->recallThreadIds = array_values(array_unique($recallThreadIds));

        if ($this->topK < 1) {
            throw new AgentException('Semantic memory topK must be greater than zero.');
        }
    }

    /**
     * @throws VectorStoreException
     */
    public function recall(string $query): array
    {
        $documents = $this->vectorStore->search(new SearchRequest(
            embedding: $this->embeddings->embedText($query),
            filters: $this->recallFilters(),
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

    /**
     * @throws VectorStoreException
     */
    public function forget(string $threadId): void
    {
        $this->vectorStore->delete($this->threadFilters($threadId));
    }

    /**
     * @throws VectorStoreException
     */
    protected function threadFilters(string $threadId): FilterGroup
    {
        return FilterGroup::and(
            Filter::eq('sourceType', static::SOURCE_TYPE),
            Filter::eq('sourceName', $threadId),
        );
    }

    /**
     * @throws VectorStoreException
     */
    protected function recallFilters(): FilterGroup
    {
        return FilterGroup::and(
            Filter::eq('sourceType', static::SOURCE_TYPE),
            Filter::in('sourceName', $this->recallThreadIds),
        );
    }

    protected function formatExchange(string $user, string $assistant): string
    {
        return "User: {$user}\nAssistant: {$assistant}";
    }
}
