<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use ArrayIterator;
use IteratorAggregate;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\CursorInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MongoDBVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;
use Traversable;

use function interface_exists;

if (!interface_exists(CursorInterface::class)) {
    // ext-mongodb is not installed offline: declare the cursor contract so aggregate() can be stubbed.
    eval('namespace MongoDB\Driver; interface CursorInterface extends \Traversable { public function toArray(): array; }');
}

class MongoDBVectorStoreSearchHydrationTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function storeReturning(array $rows): MongoDBVectorStore
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('aggregate')->willReturnCallback(
            fn (array $pipeline): CursorInterface => $this->cursor($this->applyIdProjection($pipeline, $rows)),
        );

        $client = $this->createMock(Client::class);
        $client->method('selectCollection')->willReturn($collection);

        return new MongoDBVectorStore($client, 'rag', 'chunks');
    }

    /**
     * Emulates MongoDB's $project semantics for _id, which is only excluded when projected as 0.
     *
     * @param array<int, array<string, mixed>> $pipeline
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function applyIdProjection(array $pipeline, array $rows): array
    {
        foreach ($pipeline as $stage) {
            if (($stage['$project']['_id'] ?? 1) === 0) {
                foreach ($rows as $index => $row) {
                    unset($rows[$index]['_id']);
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function cursor(array $rows): CursorInterface
    {
        return new class ($rows) implements CursorInterface, IteratorAggregate {
            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(protected array $rows)
            {
            }

            public function toArray(): array
            {
                return $this->rows;
            }

            public function getIterator(): Traversable
            {
                return new ArrayIterator($this->rows);
            }
        };
    }

    public function test_search_preserves_metadata_types(): void
    {
        $store = $this->storeReturning([[
            '_id' => 'doc-1',
            'content' => 'Hello',
            'sourceType' => 'file',
            'sourceName' => 'a.txt',
            'score' => 0.9,
            'metadata' => ['year' => 2026, 'published' => false, 'tags' => ['a', 'b'], 'author' => ['name' => 'Ada']],
        ]]);

        $results = [...$store->search(new SearchRequest([1.0, 0.0]))];

        $this->assertCount(1, $results);
        $this->assertSame(
            ['year' => 2026, 'published' => false, 'tags' => ['a', 'b'], 'author' => ['name' => 'Ada']],
            $results[0]->getMetadata(),
        );
    }

    public function test_search_returns_the_stored_document_id(): void
    {
        $store = $this->storeReturning([[
            '_id' => 'doc-1',
            'content' => 'Hello',
            'sourceType' => 'file',
            'sourceName' => 'a.txt',
            'score' => 0.9,
            'metadata' => [],
        ]]);

        $results = [...$store->search(new SearchRequest([1.0, 0.0]))];

        $this->assertInstanceOf(Document::class, $results[0]);
        $this->assertSame('doc-1', $results[0]->getId());
    }
}
