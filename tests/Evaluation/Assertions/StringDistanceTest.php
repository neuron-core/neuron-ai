<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringDistance;
use PHPUnit\Framework\TestCase;
use stdClass;
use InvalidArgumentException;

use function str_repeat;

class StringDistanceTest extends TestCase
{
    public function test_passes_with_identical_strings(): void
    {
        $assertion = new StringDistance('hello world', 0.5, 10);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_with_minor_differences(): void
    {
        $assertion = new StringDistance('hello world', 0.5, 10);
        $result = $assertion->evaluate('hello word');

        $this->assertTrue($result->passed);
        $this->assertEqualsWithDelta(0.9, $result->score, 1e-9); // distance 1 of 10
    }

    public function test_passes_within_max_distance(): void
    {
        $assertion = new StringDistance('hello', 0.3, 5);
        $result = $assertion->evaluate('helo');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.8, $result->score); // 1 - (1/5) = 0.8
    }

    public function test_fails_when_score_is_below_threshold(): void
    {
        $assertion = new StringDistance('hello world', 0.8, 10);
        $result = $assertion->evaluate('goodbye world');

        $this->assertFalse($result->passed);
        $this->assertEqualsWithDelta(0.3, $result->score, 1e-9); // distance 7 of 10
        $this->assertSame(
            "Expected 'goodbye world' to be similar to 'hello world' (distance: 7, threshold: 0.8, max_accepted: 10)",
            $result->message
        );
    }

    public function test_passes_when_score_equals_threshold(): void
    {
        $result = (new StringDistance('hello', 0.8, 5))->evaluate('helo');

        $this->assertTrue($result->passed);
        $this->assertEqualsWithDelta(0.8, $result->score, 1e-9);
    }

    public function test_distance_equal_to_max_distance_scores_zero(): void
    {
        $result = (new StringDistance('abc', 0.5, 3))->evaluate('xyz');

        $this->assertFalse($result->passed);
        $this->assertSame(0.0, $result->score);
        $this->assertSame(
            "Expected 'xyz' to be similar to 'abc' (distance: 3, threshold: 0.5, max_accepted: 3)",
            $result->message
        );
    }

    public function test_zero_threshold_accepts_any_distance_within_max(): void
    {
        $result = (new StringDistance('abc', 0.0, 3))->evaluate('xyz');

        $this->assertTrue($result->passed);
        $this->assertSame(0.0, $result->score);
    }

    public function test_distance_one_beyond_max_distance_fails_regardless_of_threshold(): void
    {
        $result = (new StringDistance('abcd', 0.0, 3))->evaluate('wxyz');

        $this->assertFalse($result->passed);
        $this->assertSame(0.0, $result->score);
        $this->assertSame("Expected 'wxyz' to be similar to 'abcd' (distance: 4, max_accepted: 3)", $result->message);
    }

    public function test_comparison_is_symmetric(): void
    {
        $forward = (new StringDistance('kitten', 0.5, 10))->evaluate('sitting');
        $backward = (new StringDistance('sitting', 0.5, 10))->evaluate('kitten');

        $this->assertEqualsWithDelta(0.7, $forward->score, 1e-9); // distance 3 of 10
        $this->assertSame($forward->score, $backward->score);
    }

    public function test_default_threshold_and_max_distance(): void
    {
        $withinDefaults = (new StringDistance(str_repeat('a', 25)))->evaluate(str_repeat('b', 25));
        $beyondHalf = (new StringDistance(str_repeat('a', 26)))->evaluate(str_repeat('b', 26));

        $this->assertTrue($withinDefaults->passed); // 1 - 25/50 = 0.5, exactly the default threshold
        $this->assertFalse($beyondHalf->passed);
        $this->assertEqualsWithDelta(0.48, $beyondHalf->score, 1e-9);
    }

    public function test_fails_when_distance_exceeds_maximum(): void
    {
        $assertion = new StringDistance('hello', 0.5, 3);
        $result = $assertion->evaluate('goodbye');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'goodbye' to be similar to 'hello' (distance: 7, max_accepted: 3)", $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new StringDistance('test', 0.5, 10);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new StringDistance('hello', 0.5, 10);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new StringDistance('test', 0.5, 10);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_fails_with_object_input(): void
    {
        $assertion = new StringDistance('test', 0.5, 10);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(new stdClass());
    }

    public function test_passes_with_empty_strings(): void
    {
        $assertion = new StringDistance('', 0.5, 10);
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_handles_single_character_difference(): void
    {
        $assertion = new StringDistance('cat', 0.5, 5);
        $result = $assertion->evaluate('bat');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.8, $result->score); // 1 - (1/5) = 0.8
    }

    public function test_handles_insertion_difference(): void
    {
        $assertion = new StringDistance('cat', 0.5, 5);
        $result = $assertion->evaluate('cart');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.8, $result->score); // 1 - (1/5) = 0.8
    }

    public function test_handles_deletion_difference(): void
    {
        $assertion = new StringDistance('cart', 0.5, 5);
        $result = $assertion->evaluate('cat');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.8, $result->score); // 1 - (1/5) = 0.8
    }

    public function test_handles_case_changes(): void
    {
        $assertion = new StringDistance('Hello', 0.5, 10);
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.9, $result->score); // 1 - (1/10) = 0.9
    }

    public function test_handles_unicode_characters(): void
    {
        $assertion = new StringDistance('café', 0.5, 5);
        $result = $assertion->evaluate('cafe');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.6, $result->score); // 1 - (2/5) = 0.6 (unicode char difference)
    }

    public function test_calculates_correct_score(): void
    {
        $assertion = new StringDistance('hello', 0.4, 10);
        $result = $assertion->evaluate('helo');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.9, $result->score); // distance = 1, score = 1 - (1/10) = 0.9
    }

    public function test_identical_strings_score_one_with_the_smallest_max_distance(): void
    {
        $assertion = new StringDistance('hello', 0.5, 1);
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_with_zero_max_distance_and_different_strings(): void
    {
        $assertion = new StringDistance('hello', 0.5, 0);
        $result = $assertion->evaluate('world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'world' to be similar to 'hello' (distance: 4, max_accepted: 0)", $result->message);
    }

    public function test_handles_long_strings(): void
    {
        $longString1 = str_repeat('a', 100);
        $longString2 = str_repeat('a', 99) . 'b';

        $assertion = new StringDistance($longString1, 0.95, 50);
        $result = $assertion->evaluate($longString2);

        $this->assertTrue($result->passed);
        $this->assertEquals(0.98, $result->score); // 1 - (1/50) = 0.98
    }

    public function test_get_name(): void
    {
        $assertion = new StringDistance('test', 0.5, 10);
        $this->assertEquals('StringDistance', $assertion->getName());
    }
}
