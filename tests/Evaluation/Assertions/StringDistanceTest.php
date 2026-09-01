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
        $this->assertGreaterThan(0.5, $result->score);
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
        $this->assertLessThan(0.8, $result->score);
        $this->assertStringContainsString("Expected 'goodbye world' to be similar to 'hello world'", $result->message);
        $this->assertStringContainsString('threshold: 0.8', $result->message);
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

    public function test_with_zero_max_distance(): void
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
