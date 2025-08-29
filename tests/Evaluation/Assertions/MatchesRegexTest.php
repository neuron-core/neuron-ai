<?php

declare(strict_types=1);

namespace Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\MatchesRegex;
use PHPUnit\Framework\TestCase;

class MatchesRegexTest extends TestCase
{
    public function testPassesWithSimpleRegexMatch(): void
    {
        $assertion = new MatchesRegex('/hello/');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWithCaseInsensitiveRegex(): void
    {
        $assertion = new MatchesRegex('/hello/i');
        $result = $assertion->evaluate('HELLO WORLD');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithComplexRegex(): void
    {
        $assertion = new MatchesRegex('/^\d{3}-\d{3}-\d{4}$/');
        $result = $assertion->evaluate('123-456-7890');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithWordBoundaries(): void
    {
        $assertion = new MatchesRegex('/\bworld\b/');
        $result = $assertion->evaluate('hello world today');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithCharacterClasses(): void
    {
        $assertion = new MatchesRegex('/[A-Za-z]+/');
        $result = $assertion->evaluate('Hello123');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithQuantifiers(): void
    {
        $assertion = new MatchesRegex('/\d{2,4}/');
        $result = $assertion->evaluate('The year is 2024');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenRegexDoesNotMatch(): void
    {
        $assertion = new MatchesRegex('/goodbye/');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to match pattern '/goodbye/'", $result->message);
    }

    public function testFailsWithStrictAnchors(): void
    {
        $assertion = new MatchesRegex('/^world$/');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to match pattern '/^world$/'", $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new MatchesRegex('/\d+/');
        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got integer', $result->message);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new MatchesRegex('/hello/');
        $result = $assertion->evaluate(['hello', 'world']);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got array', $result->message);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new MatchesRegex('/test/');
        $result = $assertion->evaluate(null);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got NULL', $result->message);
    }

    public function testFailsWithObjectInput(): void
    {
        $assertion = new MatchesRegex('/test/');
        $result = $assertion->evaluate(new \stdClass());

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got object', $result->message);
    }

    public function testPassesWithEmailRegex(): void
    {
        $assertion = new MatchesRegex('/^[^\s@]+@[^\s@]+\.[^\s@]+$/');
        $result = $assertion->evaluate('user@example.com');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWithInvalidEmail(): void
    {
        $assertion = new MatchesRegex('/^[^\s@]+@[^\s@]+\.[^\s@]+$/');
        $result = $assertion->evaluate('invalid-email');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'invalid-email' to match pattern '/^[^\s@]+@[^\s@]+\.[^\s@]+$/'", $result->message);
    }

    public function testPassesWithUnicodeCharacters(): void
    {
        $assertion = new MatchesRegex('/café/u');
        $result = $assertion->evaluate('Welcome to café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithMultilineRegex(): void
    {
        $assertion = new MatchesRegex('/first.*second/s');
        $result = $assertion->evaluate("first line\nsecond line");

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithGroups(): void
    {
        $assertion = new MatchesRegex('/(\w+)\s+(\w+)/');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithEscapedCharacters(): void
    {
        $assertion = new MatchesRegex('/\$\d+\.\d{2}/');
        $result = $assertion->evaluate('Price: $19.99');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testGetName(): void
    {
        $assertion = new MatchesRegex('/test/');
        $this->assertEquals('MatchesRegex', $assertion->getName());
    }
}
