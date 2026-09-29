<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stores that evaluate filters in PHP refuse a raw filter up front, not only when a document reaches it.
 */
class RawFilterRejectionTest extends TestCase
{
    use FileSystemSandbox;

    protected ?string $sandbox = null;

    protected function tearDown(): void
    {
        if ($this->sandbox !== null) {
            $this->removeSandbox($this->sandbox);
        }
    }

    protected function store(string $kind): VectorStoreInterface
    {
        if ($kind === 'memory') {
            return new MemoryVectorStore();
        }

        $this->sandbox = $this->createSandbox('neuron_raw_filter');

        return new FileVectorStore($this->sandbox);
    }

    /**
     * @return iterable<string, array{string, bool, FilterExpression}>
     */
    public static function rawFilters(): iterable
    {
        $raw = Filter::raw(QdrantVectorStore::class, ['key' => 'tenant']);
        $cases = [
            'an empty store' => [false, $raw],
            'an AND decided before the raw part' => [true, FilterGroup::allOf(Filter::eq('sourceType', 'nope'), $raw)],
            'an OR decided before the raw part' => [true, FilterGroup::anyOf(Filter::eq('sourceType', 'manual'), $raw)],
        ];

        foreach (['memory', 'file'] as $kind) {
            foreach ($cases as $case => [$withDocument, $filters]) {
                yield "{$kind}: {$case}" => [$kind, $withDocument, $filters];
            }
        }
    }

    #[DataProvider('rawFilters')]
    public function test_search_refuses_a_raw_filter_whatever_the_data(string $kind, bool $withDocument, FilterExpression $filters): void
    {
        $store = $this->store($kind);
        if ($withDocument) {
            $store->addDocument((new Document('a'))->setEmbedding([1, 0]));
        }

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . QdrantVectorStore::class . '; it cannot be evaluated in PHP.');

        $store->search(new SearchRequest([1, 0], $filters));
    }

    #[DataProvider('rawFilters')]
    public function test_delete_refuses_a_raw_filter_whatever_the_data(string $kind, bool $withDocument, FilterExpression $filters): void
    {
        $store = $this->store($kind);
        if ($withDocument) {
            $store->addDocument((new Document('a'))->setEmbedding([1, 0]));
        }

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . QdrantVectorStore::class . '; it cannot be evaluated in PHP.');

        $store->delete($filters);
    }
}
