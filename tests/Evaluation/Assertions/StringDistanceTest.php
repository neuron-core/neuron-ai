<?php

declare(strict_types=1);

namespace Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringDistance;
use PHPUnit\Framework\TestCase;

class StringDistanceTest extends TestCase
{
    public function testPassesWithIdenticalStrings(): void
    {
        $assertion = new StringDistance('hello world', 0.5, 10);
        $result = $assertion->evaluate('hello world');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWithMinorDifferences(): void
    {
        $assertion = new StringDistance('hello world', 0.5, 10);
        $result = $assertion->evaluate('hello word');
        
        $this->assertTrue($result->passed);
        $this->assertGreaterThan(0.5, $result->score);
    }

    public function testPassesWithinMaxDistance(): void
    {
        $assertion = new StringDistance('hello', 0.3, 5);
        $result = $assertion->evaluate('helo');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(0.8, $result->score); // 1 - (1/5) = 0.8
    }

    public function testFailsWhenScoreIsBelowThreshold(): void
    {
        $assertion = new StringDistance('hello world', 0.8, 10);
        $result = $assertion->evaluate('goodbye world');
        
        $this->assertFalse($result->passed);
        $this->assertLessThan(0.8, $result->score);
        $this->assertStringContainsString("Expected 'goodbye world' to be similar to 'hello world'", $result->message);
        $this->assertStringContainsString('threshold: 0.8', $result->message);
    }

    public function testFailsWhenDistanceExceedsMaximum(): void
    {
        $assertion = new StringDistance('hello', 0.5, 3);
        $result = $assertion->evaluate('goodbye');
        
        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'goodbye' to be similar to 'hello' (distance: 7, max_accepted: 3)", $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new StringDistance('test', 0.5, 10);
        $result = $assertion->evaluate(123);
        
        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got integer', $result->message);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new StringDistance('hello', 0.5, 10);
        $result = $assertion->evaluate(['hello', 'world']);
        
        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got array', $result->message);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new StringDistance('test', 0.5, 10);
        $result = $assertion->evaluate(null);
        
        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got NULL', $result->message);
    }

    public function testFailsWithObjectInput(): void
    {
        $assertion = new StringDistance('test', 0.5, 10);
        $result = $assertion->evaluate(new \stdClass());
        
        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got object', $result->message);
    }

    public function testPassesWithEmptyStrings(): void
    {
        $assertion = new StringDistance('', 0.5, 10);
        $result = $assertion->evaluate('');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testHandlesSingleCharacterDifference(): void
    {
        $assertion = new StringDistance('cat', 0.5, 5);
        $result = $assertion->evaluate('bat');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(0.8, $result->score); // 1 - (1/5) = 0.8
    }

    public function testHandlesInsertionDifference(): void
    {
        $assertion = new StringDistance('cat', 0.5, 5);
        $result = $assertion->evaluate('cart');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(0.8, $result->score); // 1 - (1/5) = 0.8
    }

    public function testHandlesDeletionDifference(): void
    {
        $assertion = new StringDistance('cart', 0.5, 5);
        $result = $assertion->evaluate('cat');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(0.8, $result->score); // 1 - (1/5) = 0.8
    }

    public function testHandlesCaseChanges(): void
    {
        $assertion = new StringDistance('Hello', 0.5, 10);
        $result = $assertion->evaluate('hello');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(0.9, $result->score); // 1 - (1/10) = 0.9
    }

    public function testHandlesUnicodeCharacters(): void
    {
        $assertion = new StringDistance('café', 0.5, 5);
        $result = $assertion->evaluate('cafe');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(0.6, $result->score); // 1 - (2/5) = 0.6 (unicode char difference)
    }

    public function testCalculatesCorrectScore(): void
    {
        $assertion = new StringDistance('hello', 0.4, 10);
        $result = $assertion->evaluate('helo');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(0.9, $result->score); // distance = 1, score = 1 - (1/10) = 0.9
    }

    public function testWithZeroMaxDistance(): void
    {
        $assertion = new StringDistance('hello', 0.5, 1);
        $result = $assertion->evaluate('hello');
        
        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWithZeroMaxDistanceAndDifferentStrings(): void
    {
        $assertion = new StringDistance('hello', 0.5, 0);
        $result = $assertion->evaluate('world');
        
        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'world' to be similar to 'hello' (distance: 4, max_accepted: 0)", $result->message);
    }

    public function testHandlesLongStrings(): void
    {
        $longString1 = str_repeat('a', 100);
        $longString2 = str_repeat('a', 99) . 'b';
        
        $assertion = new StringDistance($longString1, 0.95, 50);
        $result = $assertion->evaluate($longString2);
        
        $this->assertTrue($result->passed);
        $this->assertEquals(0.98, $result->score); // 1 - (1/50) = 0.98
    }

    public function testGetName(): void
    {
        $assertion = new StringDistance('test', 0.5, 10);
        $this->assertEquals('StringDistance', $assertion->getName());
    }
}