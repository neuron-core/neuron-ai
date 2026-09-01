<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringContainsAll;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class StringContainsAllTest extends TestCase
{
    public function test_passes_when_string_contains_all_keywords(): void
    {
        $assertion = new StringContainsAll(['hello', 'world']);
        $result = $assertion->evaluate('hello beautiful world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_with_case_insensitive_matching(): void
    {
        $assertion = new StringContainsAll(['HELLO', 'WORLD']);
        $result = $assertion->evaluate('hello beautiful world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_mixed_case_keywords(): void
    {
        $assertion = new StringContainsAll(['Hello', 'WORLD', 'test']);
        $result = $assertion->evaluate('hello world test case');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_some_keywords_are_missing(): void
    {
        $assertion = new StringContainsAll(['hello', 'world', 'missing']);
        $result = $assertion->evaluate('hello beautiful world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello beautiful world' to contain all keywords. Missing: missing", $result->message);
    }

    public function test_fails_with_multiple_missing_keywords(): void
    {
        $assertion = new StringContainsAll(['hello', 'missing1', 'missing2']);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain all keywords. Missing: missing1, missing2", $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new StringContainsAll(['test']);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new StringContainsAll(['hello']);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new StringContainsAll(['test']);
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_passes_with_empty_keywords_array(): void
    {
        $assertion = new StringContainsAll([]);
        $result = $assertion->evaluate('any string');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_handles_non_string_keywords(): void
    {
        $assertion = new StringContainsAll(['hello', 'world']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_non_string_keywords_are_expected(): void
    {
        $assertion = new StringContainsAll(['hello', 'missing']);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain all keywords. Missing: missing", $result->message);
    }

    public function test_passes_with_special_characters(): void
    {
        $assertion = new StringContainsAll(['!@#', '$%^']);
        $result = $assertion->evaluate('Test !@# and $%^ special chars');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_unicode_characters(): void
    {
        $assertion = new StringContainsAll(['café', 'naïve']);
        $result = $assertion->evaluate('A naïve person at café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_single_keyword(): void
    {
        $assertion = new StringContainsAll(['hello']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_get_name(): void
    {
        $assertion = new StringContainsAll(['test']);
        $this->assertEquals('StringContainsAll', $assertion->getName());
    }
}
