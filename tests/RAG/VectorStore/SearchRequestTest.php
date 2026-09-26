<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MIN;

class SearchRequestTest extends TestCase
{
    public function test_embedding_cannot_be_empty(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Search embedding cannot be empty.');

        new SearchRequest([]);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function nonNumericEmbeddings(): array
    {
        return [
            'numeric string' => [[0.1, '0.2']],
            'null' => [[0.1, null]],
            'boolean' => [[true]],
            'nested vector' => [[[0.1, 0.2]]],
        ];
    }

    /**
     * @param array<mixed> $embedding
     */
    #[DataProvider('nonNumericEmbeddings')]
    public function test_embedding_accepts_numbers_only(array $embedding): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Search embedding accepts numeric values only.');

        new SearchRequest($embedding);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveTopK(): array
    {
        return [
            'zero' => [0],
            'negative' => [-3],
            'min int' => [PHP_INT_MIN],
        ];
    }

    #[DataProvider('nonPositiveTopK')]
    public function test_top_k_must_be_positive(int $topK): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Search topK must be greater than zero.');

        new SearchRequest([1, 0], topK: $topK);
    }

    public function test_keeps_the_per_call_values(): void
    {
        $filters = Filter::eq('sourceType', 'file');

        $request = new SearchRequest([1, -0.5], $filters, 1);

        $this->assertSame([1, -0.5], $request->embedding);
        $this->assertSame($filters, $request->filters);
        $this->assertSame(1, $request->topK);
    }

    public function test_top_k_and_filters_default_to_the_store_configuration(): void
    {
        $request = new SearchRequest([0.1]);

        $this->assertNull($request->filters);
        $this->assertNull($request->topK);
    }
}
