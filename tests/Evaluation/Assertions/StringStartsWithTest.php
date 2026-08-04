<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringStartsWith;
use PHPUnit\Framework\TestCase;
use stdClass;
use InvalidArgumentException;

class StringStartsWithTest extends TestCase
{
    public function testPassesWhenStringStartsWithPrefix(): void
    {
        $assertion = new StringStartsWith('hello');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWithExactMatch(): void
    {
        $assertion = new StringStartsWith('hello');
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenStringDoesNotStartWithPrefix(): void
    {
        $assertion = new StringStartsWith('world');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to start with 'world'", $result->message);
    }

    public function testFailsWithCaseSensitiveComparison(): void
    {
        $assertion = new StringStartsWith('Hello');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to start with 'Hello'", $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new StringStartsWith('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new StringStartsWith('hello');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new StringStartsWith('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function testFailsWithObjectInput(): void
    {
        $assertion = new StringStartsWith('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(new stdClass());
    }

    public function testPassesWithEmptyPrefix(): void
    {
        $assertion = new StringStartsWith('');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithEmptyPrefixAndEmptyString(): void
    {
        $assertion = new StringStartsWith('');
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWithNonEmptyPrefixAndEmptyString(): void
    {
        $assertion = new StringStartsWith('hello');
        $result = $assertion->evaluate('');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to start with 'hello'", $result->message);
    }

    public function testPassesWithSpecialCharacters(): void
    {
        $assertion = new StringStartsWith('!@#$');
        $result = $assertion->evaluate('!@#$ special start');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithUnicodeCharacters(): void
    {
        $assertion = new StringStartsWith('café');
        $result = $assertion->evaluate('café is delicious');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithWhitespace(): void
    {
        $assertion = new StringStartsWith('  hello');
        $result = $assertion->evaluate('  hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenPrefixIsInMiddle(): void
    {
        $assertion = new StringStartsWith('world');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected response to start with 'world'", $result->message);
    }

    public function testGetName(): void
    {
        $assertion = new StringStartsWith('test');
        $this->assertEquals('StringStartsWith', $assertion->getName());
    }
}
