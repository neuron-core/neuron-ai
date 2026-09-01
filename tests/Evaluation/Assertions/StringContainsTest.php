<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringContains;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class StringContainsTest extends TestCase
{
    public function test_passes_when_string_contains_keyword(): void
    {
        $assertion = new StringContains('hello');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_with_case_insensitive_matching(): void
    {
        $assertion = new StringContains('HELLO');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_when_keyword_is_case_insensitive(): void
    {
        $assertion = new StringContains('hello');
        $result = $assertion->evaluate('HELLO WORLD');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_string_does_not_contain_keyword(): void
    {
        $assertion = new StringContains('missing');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain 'missing'", $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new StringContains('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new StringContains('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new StringContains('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_passes_with_empty_keyword_in_non_empty_string(): void
    {
        $assertion = new StringContains('');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_empty_keyword_in_empty_string(): void
    {
        $assertion = new StringContains('');
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_special_characters(): void
    {
        $assertion = new StringContains('!@#$');
        $result = $assertion->evaluate('Test !@#$ special chars');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_unicode_characters(): void
    {
        $assertion = new StringContains('café');
        $result = $assertion->evaluate('Welcome to café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_get_name(): void
    {
        $assertion = new StringContains('test');
        $this->assertEquals('StringContains', $assertion->getName());
    }
}
