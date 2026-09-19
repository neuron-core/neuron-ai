<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Retrieval;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeEmbeddingsProvider;
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

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidThreads(): iterable
    {
        yield 'empty list' => [[]];
        yield 'empty ID' => [['thread-1', '']];
        yield 'non-string ID' => [['thread-1', 42]];
    }

    /** @param array<int, mixed> $threadIds */
    #[DataProvider('invalidThreads')]
    public function test_invalid_thread_lists_are_rejected(array $threadIds): void
    {
        $this->expectException(VectorStoreException::class);
        new SemanticMemoryRetrieval(new MemoryVectorStore(), new FakeEmbeddingsProvider(), $threadIds);
    }
}
