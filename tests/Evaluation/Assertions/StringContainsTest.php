<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringContains;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class StringContainsTest extends TestCase
{
    public function testPassesWhenStringContainsKeyword(): void
    {
        $assertion = new StringContains('hello');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWithCaseInsensitiveMatching(): void
    {
        $assertion = new StringContains('HELLO');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWhenKeywordIsCaseInsensitive(): void
    {
        $assertion = new StringContains('hello');
        $result = $assertion->evaluate('HELLO WORLD');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWhenStringDoesNotContainKeyword(): void
    {
        $assertion = new StringContains('missing');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to contain 'missing'", $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new StringContains('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new StringContains('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new StringContains('test');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function testPassesWithEmptyKeywordInNonEmptyString(): void
    {
        $assertion = new StringContains('');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithEmptyKeywordInEmptyString(): void
    {
        $assertion = new StringContains('');
        $result = $assertion->evaluate('');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithSpecialCharacters(): void
    {
        $assertion = new StringContains('!@#$');
        $result = $assertion->evaluate('Test !@#$ special chars');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithUnicodeCharacters(): void
    {
        $assertion = new StringContains('café');
        $result = $assertion->evaluate('Welcome to café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testGetName(): void
    {
        $assertion = new StringContains('test');
        $this->assertEquals('StringContains', $assertion->getName());
    }
}
