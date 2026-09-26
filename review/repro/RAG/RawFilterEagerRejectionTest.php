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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function rmdir;

class RawFilterEagerRejectionTest extends TestCase
{
    protected ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            @unlink($this->directory . '/neuron.store');
            @rmdir($this->directory);
        }
    }

    protected function store(string $kind): VectorStoreInterface
    {
        if ($kind === 'memory') {
            return new MemoryVectorStore();
        }

        $this->directory = sys_get_temp_dir() . '/' . uniqid('raw-filter-', true);

        return new FileVectorStore($this->directory);
    }

    /**
     * @return array<string, array{string, bool, FilterExpression}>
     */
    public static function cases(): array
    {
        $raw = Filter::raw(QdrantVectorStore::class, ['key' => 'tenant']);
        $shortCircuitAnd = FilterGroup::allOf(Filter::eq('sourceType', 'nope'), $raw);
        $shortCircuitOr = FilterGroup::anyOf(Filter::eq('sourceType', 'manual'), $raw);

        return [
            'memory, empty store, raw' => ['memory', false, $raw],
            'memory, And short-circuits before raw' => ['memory', true, $shortCircuitAnd],
            'memory, Or short-circuits before raw' => ['memory', true, $shortCircuitOr],
            'file, empty store, raw' => ['file', false, $raw],
            'file, And short-circuits before raw' => ['file', true, $shortCircuitAnd],
            'file, Or short-circuits before raw' => ['file', true, $shortCircuitOr],
        ];
    }

    #[DataProvider('cases')]
    public function test_search_rejects_foreign_raw_filter_regardless_of_data(string $kind, bool $withDocument, FilterExpression $filters): void
    {
        $store = $this->store($kind);
        if ($withDocument) {
            $store->addDocument((new Document('a'))->setEmbedding([1, 0]));
        }

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . QdrantVectorStore::class);

        $store->search(new SearchRequest([1, 0], $filters));
    }

    #[DataProvider('cases')]
    public function test_delete_rejects_foreign_raw_filter_regardless_of_data(string $kind, bool $withDocument, FilterExpression $filters): void
    {
        $store = $this->store($kind);
        if ($withDocument) {
            $store->addDocument((new Document('a'))->setEmbedding([1, 0]));
        }

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . QdrantVectorStore::class);

        $store->delete($filters);
    }
}
