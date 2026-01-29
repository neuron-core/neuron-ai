<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringLengthBetween;
use PHPUnit\Framework\TestCase;
use stdClass;

class StringLengthBetweenTest extends TestCase
{
    public function testPassesWhenLengthIsWithinRange(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWhenLengthIsAtMinimum(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWhenLengthIsAtMaximum(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('hello world max');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWhenMinAndMaxAreEqual(): void
    {
        $assertion = new StringLengthBetween(5, 5);
        $result = $assertion->evaluate('hello');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithEmptyStringWhenMinIsZero(): void
    {
        $assertion = new StringLengthBetween(0, 5);
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenLengthIsTooShort(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('hi');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected string length to be between 5 and 15, got 2', $result->message);
    }

    public function testFailsWhenLengthIsTooLong(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('this is a very long string that exceeds the maximum length');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected string length to be between 5 and 15, got 58', $result->message);
    }

    public function testFailsWithEmptyStringWhenMinIsNotZero(): void
    {
        $assertion = new StringLengthBetween(1, 10);
        $result = $assertion->evaluate('');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected string length to be between 1 and 10, got 0', $result->message);
    }

    public function testFailsWhenMinAndMaxAreEqualButLengthDiffers(): void
    {
        $assertion = new StringLengthBetween(5, 5);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected string length to be between 5 and 5, got 11', $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got integer', $result->message);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate(['hello', 'world']);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got array', $result->message);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate(null);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got NULL', $result->message);
    }

    public function testFailsWithObjectInput(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate(new stdClass());

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got object', $result->message);
    }

    public function testPassesWithUnicodeCharacters(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('café naïve');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithSpecialCharacters(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('!@#$%^&*()');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithWhitespaceCharacters(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate('  hello  ');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithNewlineCharacters(): void
    {
        $assertion = new StringLengthBetween(10, 20);
        $result = $assertion->evaluate("hello\nworld\ntest");

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithTabCharacters(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $result = $assertion->evaluate("hello\tworld");

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testCountsEmojiCorrectly(): void
    {
        $assertion = new StringLengthBetween(8, 12);
        $result = $assertion->evaluate('hello 🌍!');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testHandlesZeroMinimum(): void
    {
        $assertion = new StringLengthBetween(0, 0);
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testGetName(): void
    {
        $assertion = new StringLengthBetween(5, 15);
        $this->assertEquals('StringLengthBetween', $assertion->getName());
    }
}
