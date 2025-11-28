<?php

declare(strict_types=1);

namespace Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringEndsWith;
use PHPUnit\Framework\TestCase;
use stdClass;

class StringEndsWithTest extends TestCase
{
    public function testPassesWhenStringEndsWithSuffix(): void
    {
        $assertion = new StringEndsWith('world');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWithExactMatch(): void
    {
        $assertion = new StringEndsWith('hello');
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenStringDoesNotEndWithSuffix(): void
    {
        $assertion = new StringEndsWith('hello');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to end with 'hello'", $result->message);
    }

    public function testFailsWithCaseSensitiveComparison(): void
    {
        $assertion = new StringEndsWith('World');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to end with 'World'", $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new StringEndsWith('test');
        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got integer', $result->message);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new StringEndsWith('world');
        $result = $assertion->evaluate(['hello', 'world']);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got array', $result->message);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new StringEndsWith('test');
        $result = $assertion->evaluate(null);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got NULL', $result->message);
    }

    public function testFailsWithObjectInput(): void
    {
        $assertion = new StringEndsWith('test');
        $result = $assertion->evaluate(new stdClass());

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got object', $result->message);
    }

    public function testPassesWithEmptySuffix(): void
    {
        $assertion = new StringEndsWith('');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithEmptySuffixAndEmptyString(): void
    {
        $assertion = new StringEndsWith('');
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWithNonEmptySuffixAndEmptyString(): void
    {
        $assertion = new StringEndsWith('world');
        $result = $assertion->evaluate('');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to end with 'world'", $result->message);
    }

    public function testPassesWithSpecialCharacters(): void
    {
        $assertion = new StringEndsWith('!@#$');
        $result = $assertion->evaluate('special end !@#$');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithUnicodeCharacters(): void
    {
        $assertion = new StringEndsWith('café');
        $result = $assertion->evaluate('Welcome to café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithWhitespace(): void
    {
        $assertion = new StringEndsWith('world  ');
        $result = $assertion->evaluate('hello world  ');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenSuffixIsAtBeginning(): void
    {
        $assertion = new StringEndsWith('hello');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to end with 'hello'", $result->message);
    }

    public function testPassesWithPunctuationSuffix(): void
    {
        $assertion = new StringEndsWith('.');
        $result = $assertion->evaluate('This is a sentence.');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testGetName(): void
    {
        $assertion = new StringEndsWith('test');
        $this->assertEquals('StringEndsWith', $assertion->getName());
    }
}
