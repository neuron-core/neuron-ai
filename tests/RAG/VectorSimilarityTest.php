<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorSimilarity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sqrt;

class VectorSimilarityTest extends TestCase
{
    /** @return iterable<string, array{array<int, int|float>, array<int, int|float>, float}> */
    public static function knownSimilarities(): iterable
    {
        yield 'identical' => [[1, 2, 3], [1, 2, 3], 1.0];
        yield 'same direction, different magnitude' => [[3, 4], [30, 40], 1.0];
        yield 'orthogonal' => [[1, 0], [0, 1], 0.0];
        yield 'opposite' => [[1, 0], [-1, 0], -1.0];
        yield 'negative components in the same direction' => [[-1, -2, -3], [-2, -4, -6], 1.0];
        yield 'sixty degrees apart' => [[1, 0], [0.5, sqrt(3) / 2], 0.5];
        yield 'mixed signs' => [[1, -1], [1, 1], 0.0];
        yield 'small float embeddings' => [[0.1, 0.2, 0.3], [0.1, 0.2, 0.3], 1.0];
        yield 'single dimension opposite' => [[0.25], [-4], -1.0];
    }

    /**
     * @param array<int, int|float> $first
     * @param array<int, int|float> $second
     */
    #[DataProvider('knownSimilarities')]
    public function test_cosine_similarity_matches_the_angle_between_vectors(array $first, array $second, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, VectorSimilarity::cosineSimilarity($first, $second), 1e-12);
        $this->assertEqualsWithDelta(1 - $expected, VectorSimilarity::cosineDistance($first, $second), 1e-12);
    }

    public function test_cosine_similarity_is_symmetric(): void
    {
        $first = [0.3, -0.7, 0.2, 0.9];
        $second = [0.5, 0.1, -0.4, 0.6];

        $this->assertSame(
            VectorSimilarity::cosineSimilarity($first, $second),
            VectorSimilarity::cosineSimilarity($second, $first),
        );
    }

    public function test_cosine_similarity_stays_within_unit_range(): void
    {
        $first = [0.12, -0.98, 0.33, 0.51, -0.27];
        $second = [-0.44, 0.61, 0.05, -0.73, 0.19];

        $similarity = VectorSimilarity::cosineSimilarity($first, $second);

        $this->assertGreaterThanOrEqual(-1.0, $similarity);
        $this->assertLessThanOrEqual(1.0, $similarity);
    }

    /** @return iterable<string, array{array<int, int|float>, array<int, int|float>}> */
    public static function vectorsWithoutMagnitude(): iterable
    {
        yield 'first is zero' => [[0, 0, 0], [1, 2, 3]];
        yield 'second is zero' => [[1, 2, 3], [0.0, 0.0, 0.0]];
        yield 'both are zero' => [[0, 0], [0, 0]];
        yield 'both are empty' => [[], []];
    }

    /**
     * @param array<int, int|float> $first
     * @param array<int, int|float> $second
     */
    #[DataProvider('vectorsWithoutMagnitude')]
    public function test_a_vector_without_magnitude_has_no_similarity_instead_of_dividing_by_zero(array $first, array $second): void
    {
        $this->assertSame(0.0, VectorSimilarity::cosineSimilarity($first, $second));
        $this->assertSame(1.0, VectorSimilarity::cosineDistance($first, $second));
    }

    /** @return iterable<string, array{array<int, int|float>, array<int, int|float>}> */
    public static function mismatchedDimensions(): iterable
    {
        yield 'shorter first' => [[1, 2], [1, 2, 3]];
        yield 'shorter second' => [[1, 2, 3], [1, 2]];
        yield 'one empty' => [[], [1.0]];
    }

    /**
     * @param array<int, int|float> $first
     * @param array<int, int|float> $second
     */
    #[DataProvider('mismatchedDimensions')]
    public function test_vectors_of_different_dimensions_are_rejected(array $first, array $second): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Vectors must have the same length to apply cosine similarity.');

        VectorSimilarity::cosineSimilarity($first, $second);
    }

    public function test_cosine_distance_rejects_vectors_of_different_dimensions(): void
    {
        $this->expectException(VectorStoreException::class);

        VectorSimilarity::cosineDistance([1, 2], [1, 2, 3]);
    }

    /** @return iterable<string, array{float, float}> */
    public static function distances(): iterable
    {
        yield 'no distance' => [0.0, 1.0];
        yield 'orthogonal' => [1.0, 0.0];
        yield 'opposite' => [2.0, -1.0];
        yield 'partial' => [0.25, 0.75];
    }

    #[DataProvider('distances')]
    public function test_similarity_from_distance_inverts_cosine_distance(float $distance, float $similarity): void
    {
        $this->assertSame($similarity, VectorSimilarity::similarityFromDistance($distance));
    }

    public function test_distance_and_similarity_round_trip(): void
    {
        $first = [0.2, 0.4, -0.1];
        $second = [0.3, -0.2, 0.8];

        $this->assertEqualsWithDelta(
            VectorSimilarity::cosineSimilarity($first, $second),
            VectorSimilarity::similarityFromDistance(VectorSimilarity::cosineDistance($first, $second)),
            1e-15,
        );
    }
}
