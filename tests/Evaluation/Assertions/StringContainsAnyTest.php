<?php

declare(strict_types=1);

namespace Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringContainsAny;
use PHPUnit\Framework\TestCase;

class StringContainsAnyTest extends TestCase
{
    public function testPassesWhenStringContainsOneKeyword(): void
    {
        $assertion = new StringContainsAny(['hello', 'missing']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWhenStringContainsMultipleKeywords(): void
    {
        $assertion = new StringContainsAny(['hello', 'world']);
        $result = $assertion->evaluate('hello beautiful world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithCaseInsensitiveMatching(): void
    {
        $assertion = new StringContainsAny(['HELLO', 'MISSING']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithMixedCaseKeywords(): void
    {
        $assertion = new StringContainsAny(['Hello', 'MISSING', 'test']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenNoKeywordsAreFound(): void
    {
        $assertion = new StringContainsAny(['missing1', 'missing2']);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain any of: missing1, missing2", $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new StringContainsAny(['test']);
        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got integer', $result->message);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new StringContainsAny(['hello']);
        $result = $assertion->evaluate(['hello', 'world']);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got array', $result->message);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new StringContainsAny(['test']);
        $result = $assertion->evaluate(null);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got NULL', $result->message);
    }

    public function testPassesWithEmptyKeywordsArray(): void
    {
        $assertion = new StringContainsAny([]);
        $result = $assertion->evaluate('any string');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'any string' to contain any of: ", $result->message);
    }

    public function testHandlesNonStringKeywords(): void
    {
        $assertion = new StringContainsAny([123, 'hello', 456]);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenOnlyNonStringKeywordsProvided(): void
    {
        $assertion = new StringContainsAny([123, 456, true]);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain any of: 123, 456, 1", $result->message);
    }

    public function testPassesWithSpecialCharacters(): void
    {
        $assertion = new StringContainsAny(['!@#', 'missing']);
        $result = $assertion->evaluate('Test !@# special chars');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithUnicodeCharacters(): void
    {
        $assertion = new StringContainsAny(['café', 'missing']);
        $result = $assertion->evaluate('Welcome to café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithSingleKeyword(): void
    {
        $assertion = new StringContainsAny(['hello']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithFirstMatchingKeyword(): void
    {
        $assertion = new StringContainsAny(['hello', 'world', 'test']);
        $result = $assertion->evaluate('hello beautiful day');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testGetName(): void
    {
        $assertion = new StringContainsAny(['test']);
        $this->assertEquals('StringContainsAny', $assertion->getName());
    }
}
