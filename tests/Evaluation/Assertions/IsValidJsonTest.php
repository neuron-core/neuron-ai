<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\IsValidJson;
use PHPUnit\Framework\TestCase;
use stdClass;

class IsValidJsonTest extends TestCase
{
    public function testPassesWithValidJsonObject(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"name": "John", "age": 30}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function testPassesWithValidJsonArray(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('[1, 2, 3, "hello"]');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithValidJsonString(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('"hello world"');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithValidJsonNumber(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('42');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithValidJsonBoolean(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('true');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithValidJsonNull(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('null');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithNestedJson(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"users": [{"name": "John", "active": true}, {"name": "Jane", "active": false}]}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithEmptyObject(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithEmptyArray(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('[]');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWithInvalidJsonSyntax(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"name": "John", "age": 30,}');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function testFailsWithUnquotedKeys(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{name: "John", age: 30}');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function testFailsWithSingleQuotes(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate("{'name': 'John', 'age': 30}");

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function testFailsWithMissingQuotes(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{name: John, age: 30}');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function testFailsWithPlainText(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function testFailsWithEmptyString(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function testFailsWithNonStringInput(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got integer', $result->message);
        $this->assertEquals(['actual' => 123], $result->context);
    }

    public function testFailsWithArrayInput(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate(['hello', 'world']);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got array', $result->message);
        $this->assertEquals(['actual' => ['hello', 'world']], $result->context);
    }

    public function testFailsWithNullInput(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate(null);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got NULL', $result->message);
        $this->assertEquals(['actual' => null], $result->context);
    }

    public function testFailsWithObjectInput(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate(new stdClass());

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got object', $result->message);
    }

    public function testFailsWithUnclosedBrackets(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"name": "John"');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function testFailsWithUnclosedArray(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('[1, 2, 3');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function testPassesWithUnicodeCharacters(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"café": "délicieux", "naïve": true}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithEscapedCharacters(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"quote": "He said \"hello\"", "newline": "Line 1\\nLine 2"}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testPassesWithLargeNumbers(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"big": 9223372036854775807, "decimal": 3.14159}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testGetName(): void
    {
        $assertion = new IsValidJson();
        $this->assertEquals('IsValidJson', $assertion->getName());
    }
}
