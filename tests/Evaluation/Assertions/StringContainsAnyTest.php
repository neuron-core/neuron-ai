<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringContainsAny;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class StringContainsAnyTest extends TestCase
{
    public function test_passes_when_string_contains_one_keyword(): void
    {
        $assertion = new StringContainsAny(['hello', 'missing']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_when_string_contains_multiple_keywords(): void
    {
        $assertion = new StringContainsAny(['hello', 'world']);
        $result = $assertion->evaluate('hello beautiful world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_case_insensitive_matching(): void
    {
        $assertion = new StringContainsAny(['HELLO', 'MISSING']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_mixed_case_keywords(): void
    {
        $assertion = new StringContainsAny(['Hello', 'MISSING', 'test']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_no_keywords_are_found(): void
    {
        $assertion = new StringContainsAny(['missing1', 'missing2']);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain any of: missing1, missing2", $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new StringContainsAny(['test']);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new StringContainsAny(['hello']);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new StringContainsAny(['test']);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_passes_with_empty_keywords_array(): void
    {
        $assertion = new StringContainsAny([]);
        $result = $assertion->evaluate('any string');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'any string' to contain any of: ", $result->message);
    }

    public function test_handles_non_string_keywords(): void
    {
        $assertion = new StringContainsAny([123, 'hello', 456]);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_only_non_string_keywords_provided(): void
    {
        $assertion = new StringContainsAny([123, 456, true]);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain any of: 123, 456, 1", $result->message);
    }

    public function test_passes_with_special_characters(): void
    {
        $assertion = new StringContainsAny(['!@#', 'missing']);
        $result = $assertion->evaluate('Test !@# special chars');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_unicode_characters(): void
    {
        $assertion = new StringContainsAny(['café', 'missing']);
        $result = $assertion->evaluate('Welcome to café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_single_keyword(): void
    {
        $assertion = new StringContainsAny(['hello']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_first_matching_keyword(): void
    {
        $assertion = new StringContainsAny(['hello', 'world', 'test']);
        $result = $assertion->evaluate('hello beautiful day');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_get_name(): void
    {
        $assertion = new StringContainsAny(['test']);
        $this->assertEquals('StringContainsAny', $assertion->getName());
    }
}
