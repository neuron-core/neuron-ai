<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Retrieval;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

class SemanticMemoryRetrievalTest extends TestCase
{
    public function test_only_explicit_threads_and_conversation_documents_are_retrieved(): void
    {
        $store = new MemoryVectorStore(topK: 10);
        $embeddings = new FakeEmbeddingsProvider();
        foreach (['thread-1', 'thread-2', 'thread-3'] as $threadId) {
            $store->addDocument($embeddings->embedDocument(
                (new Document($threadId))->setSourceType(SemanticMemoryRetrieval::SOURCE_TYPE)->setSourceName($threadId),
            ));
        }
        $store->addDocument($embeddings->embedDocument(
            (new Document('Knowledge'))->setSourceType('knowledge')->setSourceName('thread-1'),
        ));
        $retrieval = new SemanticMemoryRetrieval($store, $embeddings, ['thread-1', 'thread-2', 'thread-1']);
        $query = new UserMessage('What do you remember?');

        $documents = $retrieval->retrieve($query);
        $this->assertEqualsCanonicalizing(['thread-1', 'thread-2'], array_map(
            static fn (Document $document): string => $document->getContent(),
            $documents,
        ));
        $this->assertNotNull($documents[0]->getEmbedding());
        $this->assertNotNull($documents[0]->getScore());

        $narrowed = $retrieval->retrieve($query, Filter::eq('sourceName', 'thread-2'));
        $this->assertCount(1, $narrowed);
        $this->assertSame('thread-2', $narrowed[0]->getSourceName());
        $this->assertSame([], $retrieval->retrieve($query, Filter::eq('sourceName', 'thread-3')));
        $this->assertCount(2, $retrieval->retrieve($query));
    }

    public function test_store_top_k_limits_results_across_all_allowed_threads(): void
    {
        $store = new MemoryVectorStore(topK: 1);
        $embeddings = new FakeEmbeddingsProvider();
        foreach (['one', 'two'] as $threadId) {
            $store->addDocument($embeddings->embedDocument(
                (new Document($threadId))->setSourceType(SemanticMemoryRetrieval::SOURCE_TYPE)->setSourceName($threadId),
            ));
        }
        $this->assertCount(1, (new SemanticMemoryRetrieval($store, $embeddings, ['one', 'two']))
            ->retrieve(new UserMessage('Question')));
    }

    public function test_an_injected_or_filter_cannot_reach_other_threads_or_other_sources(): void
    {
        $store = new MemoryVectorStore(topK: 10);
        $embeddings = new FakeEmbeddingsProvider();
        foreach (['allowed', 'private'] as $threadId) {
            $store->addDocument($embeddings->embedDocument(
                (new Document($threadId))->setSourceType(SemanticMemoryRetrieval::SOURCE_TYPE)->setSourceName($threadId),
            ));
        }
        $store->addDocument($embeddings->embedDocument((new Document('knowledge'))->setSourceType('file')->setSourceName('allowed')));
        $retrieval = new SemanticMemoryRetrieval($store, $embeddings, ['allowed']);

        $documents = $retrieval->retrieve(
            new UserMessage('Question'),
            FilterGroup::anyOf(Filter::eq('sourceName', 'private'), Filter::eq('sourceType', 'file'), Filter::eq('sourceName', 'allowed')),
        );

        $this->assertSame(['allowed'], array_map(static fn (Document $document): string => $document->getContent(), $documents));
    }

    public function test_the_thread_allowlist_is_searched_as_a_deduplicated_conversation_scope(): void
    {
        $store = new FakeVectorStore();

        (new SemanticMemoryRetrieval($store, new FakeEmbeddingsProvider(), ['b', 'a', 'b']))->retrieve(new UserMessage('Question'));

        $store->assertSearchedWithFilters(FilterGroup::and(
            Filter::eq('sourceType', 'conversation'),
            Filter::in('sourceName', ['b', 'a']),
        ));
    }

    /** @return iterable<string, array{array<int, mixed>, string}> */
    public static function invalidThreads(): iterable
    {
        yield 'empty list' => [[], 'Semantic memory retrieval requires at least one thread ID.'];
        yield 'empty ID' => [['thread-1', ''], 'Semantic memory thread IDs must be non-empty strings.'];
        yield 'non-string ID' => [['thread-1', 42], 'Semantic memory thread IDs must be non-empty strings.'];
        yield 'null ID' => [[null], 'Semantic memory thread IDs must be non-empty strings.'];
    }

    /** @param array<int, mixed> $threadIds */
    #[DataProvider('invalidThreads')]
    public function test_invalid_thread_lists_are_rejected(array $threadIds, string $message): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage($message);

        new SemanticMemoryRetrieval(new MemoryVectorStore(), new FakeEmbeddingsProvider(), $threadIds);
    }
}
