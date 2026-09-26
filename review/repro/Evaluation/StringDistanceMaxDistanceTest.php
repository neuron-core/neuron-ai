<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Evaluation\Assertions\StringDistance;
use PHPUnit\Framework\TestCase;

class StringDistanceMaxDistanceTest extends TestCase
{
    public function test_zero_max_distance_passes_identical_strings_with_full_score(): void
    {
        $result = (new StringDistance('hello', 0.5, 0))->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertSame(1.0, $result->score);
    }

    public function test_zero_max_distance_fails_any_difference(): void
    {
        $result = (new StringDistance('hello', 0.5, 0))->evaluate('hellO');

        $this->assertFalse($result->passed);
        $this->assertSame(0.0, $result->score);
    }

    public function test_negative_max_distance_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StringDistance('hello', 0.5, -1);
    }
}
