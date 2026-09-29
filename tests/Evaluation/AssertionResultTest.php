<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use InvalidArgumentException;
use NeuronAI\Evaluation\AssertionResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const INF;
use const NAN;

class AssertionResultTest extends TestCase
{
    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteScores(): iterable
    {
        yield 'NAN' => [NAN];
        yield 'INF' => [INF];
        yield '-INF' => [-INF];
    }

    #[DataProvider('nonFiniteScores')]
    public function test_a_non_finite_score_is_rejected(float $score): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An assertion score must be a finite number');

        AssertionResult::pass($score);
    }

    public function test_a_negative_score_is_a_valid_score(): void
    {
        // Cosine similarity ranges from -1 to 1
        $this->assertSame(-0.2, AssertionResult::fail(-0.2, 'dissimilar')->score);
    }
}
