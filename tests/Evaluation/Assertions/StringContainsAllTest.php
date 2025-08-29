<?php

declare(strict_types=1);

namespace Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringContainsAll;
use PHPUnit\Framework\TestCase;

class StringContainsAllTest extends TestCase
{
    public function testPassesWhenStringContainsAllKeywords(): void
    {
        $assertion = new StringContainsAll(['hello', 'world']);
        $result = $assertion->evaluate('hello beautiful world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWithCaseInsensitiveMatching(): void
    {
        $assertion = new StringContainsAll(['HELLO', 'WORLD']);
        $result = $assertion->evaluate('hello beautiful world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithMixedCaseKeywords(): void
    {
        $assertion = new StringContainsAll(['Hello', 'WORLD', 'test']);
        $result = $assertion->evaluate('hello world test case');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenSomeKeywordsAreMissing(): void
    {
        $assertion = new StringContainsAll(['hello', 'world', 'missing']);
        $result = $assertion->evaluate('hello beautiful world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello beautiful world' to contain all keywords. Missing: missing", $result->message);
    }

    public function testFailsWithMultipleMissingKeywords(): void
    {
        $assertion = new StringContainsAll(['hello', 'missing1', 'missing2']);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain all keywords. Missing: missing1, missing2", $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new StringContainsAll(['test']);
        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got integer', $result->message);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new StringContainsAll(['hello']);
        $result = $assertion->evaluate(['hello', 'world']);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got array', $result->message);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new StringContainsAll(['test']);
        $result = $assertion->evaluate(null);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got NULL', $result->message);
    }

    public function testPassesWithEmptyKeywordsArray(): void
    {
        $assertion = new StringContainsAll([]);
        $result = $assertion->evaluate('any string');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testHandlesNonStringKeywords(): void
    {
        $assertion = new StringContainsAll(['hello', 123, 'world']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenNonStringKeywordsAreExpected(): void
    {
        $assertion = new StringContainsAll(['hello', 123, 'missing']);
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain all keywords. Missing: missing", $result->message);
    }

    public function testPassesWithSpecialCharacters(): void
    {
        $assertion = new StringContainsAll(['!@#', '$%^']);
        $result = $assertion->evaluate('Test !@# and $%^ special chars');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithUnicodeCharacters(): void
    {
        $assertion = new StringContainsAll(['café', 'naïve']);
        $result = $assertion->evaluate('A naïve person at café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithSingleKeyword(): void
    {
        $assertion = new StringContainsAll(['hello']);
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testGetName(): void
    {
        $assertion = new StringContainsAll(['test']);
        $this->assertEquals('StringContainsAll', $assertion->getName());
    }
}
