<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringLengthBetween;
use PHPUnit\Framework\TestCase;
use stdClass;
use InvalidArgumentException;

class StringLengthBetweenTest extends TestCase
{
    public function test_passes_when_length_is_within_range(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_when_length_is_at_minimum(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_when_length_is_at_maximum(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('hello world max');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_when_min_and_max_are_equal(): void
    {
        $assertion = new StringLengthBetween(5, 5);
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_empty_string_when_min_is_zero(): void
    {
        $assertion = new StringLengthBetween(0, 5);
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_length_is_too_short(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('hi');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected string length to be between 5 and 15, got 2', $result->message);
    }

    public function test_fails_when_length_is_too_long(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('this is a very long string that exceeds the maximum length');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected string length to be between 5 and 15, got 58', $result->message);
    }

    public function test_fails_with_empty_string_when_min_is_not_zero(): void
    {
        $assertion = new StringLengthBetween(1, 10);
        $result = $assertion->evaluate('');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected string length to be between 1 and 10, got 0', $result->message);
    }

    public function test_fails_when_min_and_max_are_equal_but_length_differs(): void
    {
        $assertion = new StringLengthBetween(5, 5);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected string length to be between 5 and 5, got 11', $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_fails_with_object_input(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(new stdClass());
    }

    public function test_passes_with_unicode_characters(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('café naïve');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_special_characters(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('!@#$%^&*()');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_whitespace_characters(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('  hello  ');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_newline_characters(): void
    {
        $assertion = new StringLengthBetween(10, 20);
        $result = $assertion->evaluate("hello\nworld\ntest");

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_tab_characters(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate("hello\tworld");

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_counts_emoji_correctly(): void
    {
        $assertion = new StringLengthBetween(8, 12);
        $result = $assertion->evaluate('hello 🌍!');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_handles_zero_minimum(): void
    {
        $assertion = new StringLengthBetween(0, 0);
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_get_name(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $this->assertEquals('StringLengthBetween', $assertion->getName());
    }
}
