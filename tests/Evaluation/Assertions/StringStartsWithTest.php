<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringStartsWith;
use PHPUnit\Framework\TestCase;
use stdClass;
use InvalidArgumentException;

class StringStartsWithTest extends TestCase
{
    public function test_passes_when_string_starts_with_prefix(): void
    {
        $assertion = new StringStartsWith('hello');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_with_exact_match(): void
    {
        $assertion = new StringStartsWith('hello');
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_string_does_not_start_with_prefix(): void
    {
        $assertion = new StringStartsWith('world');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to start with 'world'", $result->message);
    }

    public function test_fails_with_case_sensitive_comparison(): void
    {
        $assertion = new StringStartsWith('Hello');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to start with 'Hello'", $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new StringStartsWith('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new StringStartsWith('hello');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new StringStartsWith('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_fails_with_object_input(): void
    {
        $assertion = new StringStartsWith('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(new stdClass());
    }

    public function test_passes_with_empty_prefix(): void
    {
        $assertion = new StringStartsWith('');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_empty_prefix_and_empty_string(): void
    {
        $assertion = new StringStartsWith('');
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_with_non_empty_prefix_and_empty_string(): void
    {
        $assertion = new StringStartsWith('hello');
        $result = $assertion->evaluate('');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to start with 'hello'", $result->message);
    }

    public function test_passes_with_special_characters(): void
    {
        $assertion = new StringStartsWith('!@#$');
        $result = $assertion->evaluate('!@#$ special start');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_unicode_characters(): void
    {
        $assertion = new StringStartsWith('café');
        $result = $assertion->evaluate('café is delicious');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_whitespace(): void
    {
        $assertion = new StringStartsWith('  hello');
        $result = $assertion->evaluate('  hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_prefix_is_in_middle(): void
    {
        $assertion = new StringStartsWith('world');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to start with 'world'", $result->message);
    }

    public function test_get_name(): void
    {
        $assertion = new StringStartsWith('test');
        $this->assertEquals('StringStartsWith', $assertion->getName());
    }
}
