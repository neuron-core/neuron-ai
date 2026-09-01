<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\IsValidJson;
use PHPUnit\Framework\TestCase;
use stdClass;
use InvalidArgumentException;

class IsValidJsonTest extends TestCase
{
    public function test_passes_with_valid_json_object(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"name": "John", "age": 30}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_with_valid_json_array(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('[1, 2, 3, "hello"]');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_valid_json_string(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('"hello world"');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_valid_json_number(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('42');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_valid_json_boolean(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('true');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_valid_json_null(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('null');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_nested_json(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"users": [{"name": "John", "active": true}, {"name": "Jane", "active": false}]}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_empty_object(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_empty_array(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('[]');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_with_invalid_json_syntax(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"name": "John", "age": 30,}');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function test_fails_with_unquoted_keys(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{name: "John", age: 30}');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function test_fails_with_single_quotes(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate("{'name': 'John', 'age': 30}");

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function test_fails_with_missing_quotes(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{name: John, age: 30}');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function test_fails_with_plain_text(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function test_fails_with_empty_string(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new IsValidJson();
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new IsValidJson();
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new IsValidJson();
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_fails_with_object_input(): void
    {
        $assertion = new IsValidJson();
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(new stdClass());
    }

    public function test_fails_with_unclosed_brackets(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"name": "John"');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function test_fails_with_unclosed_array(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('[1, 2, 3');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected valid JSON response:', $result->message);
    }

    public function test_passes_with_unicode_characters(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"café": "délicieux", "naïve": true}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_escaped_characters(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"quote": "He said \"hello\"", "newline": "Line 1\\nLine 2"}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_large_numbers(): void
    {
        $assertion = new IsValidJson();
        $result = $assertion->evaluate('{"big": 9223372036854775807, "decimal": 3.14159}');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_get_name(): void
    {
        $assertion = new IsValidJson();
        $this->assertEquals('IsValidJson', $assertion->getName());
    }
}
