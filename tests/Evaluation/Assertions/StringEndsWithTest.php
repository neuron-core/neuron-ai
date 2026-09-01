<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringEndsWith;
use PHPUnit\Framework\TestCase;
use stdClass;
use InvalidArgumentException;

class StringEndsWithTest extends TestCase
{
    public function test_passes_when_string_ends_with_suffix(): void
    {
        $assertion = new StringEndsWith('world');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_with_exact_match(): void
    {
        $assertion = new StringEndsWith('hello');
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_string_does_not_end_with_suffix(): void
    {
        $assertion = new StringEndsWith('hello');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to end with 'hello'", $result->message);
    }

    public function test_fails_with_case_sensitive_comparison(): void
    {
        $assertion = new StringEndsWith('World');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to end with 'World'", $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new StringEndsWith('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new StringEndsWith('world');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new StringEndsWith('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_fails_with_object_input(): void
    {
        $assertion = new StringEndsWith('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(new stdClass());
    }

    public function test_passes_with_empty_suffix(): void
    {
        $assertion = new StringEndsWith('');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_empty_suffix_and_empty_string(): void
    {
        $assertion = new StringEndsWith('');
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_with_non_empty_suffix_and_empty_string(): void
    {
        $assertion = new StringEndsWith('world');
        $result = $assertion->evaluate('');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to end with 'world'", $result->message);
    }

    public function test_passes_with_special_characters(): void
    {
        $assertion = new StringEndsWith('!@#$');
        $result = $assertion->evaluate('special end !@#$');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_unicode_characters(): void
    {
        $assertion = new StringEndsWith('café');
        $result = $assertion->evaluate('Welcome to café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_whitespace(): void
    {
        $assertion = new StringEndsWith('world  ');
        $result = $assertion->evaluate('hello world  ');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_suffix_is_at_beginning(): void
    {
        $assertion = new StringEndsWith('hello');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to end with 'hello'", $result->message);
    }

    public function test_passes_with_punctuation_suffix(): void
    {
        $assertion = new StringEndsWith('.');
        $result = $assertion->evaluate('This is a sentence.');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_get_name(): void
    {
        $assertion = new StringEndsWith('test');
        $this->assertEquals('StringEndsWith', $assertion->getName());
    }
}
