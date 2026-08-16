<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

class SearchRequestTest extends TestCase
{
    public function test_embedding_cannot_be_empty(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('cannot be empty');

        new SearchRequest([]);
    }

    public function test_top_k_must_be_positive(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('greater than zero');

        new SearchRequest([1, 0], topK: 0);
    }
}
