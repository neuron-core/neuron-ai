<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorSimilarity;
use PHPUnit\Framework\TestCase;

class VectorSimilarityTest extends TestCase
{
    public function test_identical_vectors(): void
    {
        $v1 = [1, 2, 3];
        $v2 = [1, 2, 3];
        $this->assertEquals(1.0, VectorSimilarity::cosineSimilarity($v1, $v2));
        $this->assertEquals(0.0, VectorSimilarity::cosineDistance($v1, $v2));
    }

    public function test_orthogonal_vectors(): void
    {
        $v1 = [1, 0];
        $v2 = [0, 1];
        $this->assertEquals(0.0, VectorSimilarity::cosineSimilarity($v1, $v2));
        $this->assertEquals(1.0, VectorSimilarity::cosineDistance($v1, $v2));
    }

    public function test_opposite_vectors(): void
    {
        $v1 = [1, 0];
        $v2 = [-1, 0];
        $this->assertEquals(-1.0, VectorSimilarity::cosineSimilarity($v1, $v2));
        $this->assertEquals(2.0, VectorSimilarity::cosineDistance($v1, $v2));
    }

    public function test_zero_vector(): void
    {
        $v1 = [0, 0, 0];
        $v2 = [1, 2, 3];
        $this->assertEquals(0.0, VectorSimilarity::cosineSimilarity($v1, $v2));
        $this->assertEquals(1.0, VectorSimilarity::cosineDistance($v1, $v2));
    }

    public function test_different_length_vectors(): void
    {
        $this->expectException(VectorStoreException::class);
        VectorSimilarity::cosineSimilarity([1, 2], [1, 2, 3]);
    }
}
