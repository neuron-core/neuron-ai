<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\MatchesRegex;
use PHPUnit\Framework\TestCase;
use stdClass;
use InvalidArgumentException;

class MatchesRegexTest extends TestCase
{
    public function test_passes_with_simple_regex_match(): void
    {
        $assertion = new MatchesRegex('/hello/');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('', $result->message);
    }

    public function test_passes_with_case_insensitive_regex(): void
    {
        $assertion = new MatchesRegex('/hello/i');
        $result = $assertion->evaluate('HELLO WORLD');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_complex_regex(): void
    {
        $assertion = new MatchesRegex('/^\d{3}-\d{3}-\d{4}$/');
        $result = $assertion->evaluate('123-456-7890');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_word_boundaries(): void
    {
        $assertion = new MatchesRegex('/\bworld\b/');
        $result = $assertion->evaluate('hello world today');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_character_classes(): void
    {
        $assertion = new MatchesRegex('/[A-Za-z]+/');
        $result = $assertion->evaluate('Hello123');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_quantifiers(): void
    {
        $assertion = new MatchesRegex('/\d{2,4}/');
        $result = $assertion->evaluate('The year is 2024');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_when_regex_does_not_match(): void
    {
        $assertion = new MatchesRegex('/goodbye/');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to match pattern '/goodbye/'", $result->message);
    }

    public function test_fails_with_strict_anchors(): void
    {
        $assertion = new MatchesRegex('/^world$/');
        $result = $assertion->evaluate('hello world');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'hello world' to match pattern '/^world$/'", $result->message);
    }

    public function test_fails_with_non_string_input(): void
    {
        $assertion = new MatchesRegex('/\d+/');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_array_input(): void
    {
        $assertion = new MatchesRegex('/hello/');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['hello', 'world']);
    }

    public function test_fails_with_null_input(): void
    {
        $assertion = new MatchesRegex('/test/');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_fails_with_object_input(): void
    {
        $assertion = new MatchesRegex('/test/');
        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(new stdClass());
    }

    public function test_passes_with_email_regex(): void
    {
        $assertion = new MatchesRegex('/^[^\s@]+@[^\s@]+\.[^\s@]+$/');
        $result = $assertion->evaluate('user@example.com');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_with_invalid_email(): void
    {
        $assertion = new MatchesRegex('/^[^\s@]+@[^\s@]+\.[^\s@]+$/');
        $result = $assertion->evaluate('invalid-email');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals("Expected 'invalid-email' to match pattern '/^[^\s@]+@[^\s@]+\.[^\s@]+$/'", $result->message);
    }

    public function test_passes_with_unicode_characters(): void
    {
        $assertion = new MatchesRegex('/café/u');
        $result = $assertion->evaluate('Welcome to café');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_multiline_regex(): void
    {
        $assertion = new MatchesRegex('/first.*second/s');
        $result = $assertion->evaluate("first line\nsecond line");

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_groups(): void
    {
        $assertion = new MatchesRegex('/(\w+)\s+(\w+)/');
        $result = $assertion->evaluate('hello world');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_passes_with_escaped_characters(): void
    {
        $assertion = new MatchesRegex('/\$\d+\.\d{2}/');
        $result = $assertion->evaluate('Price: $19.99');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_get_name(): void
    {
        $assertion = new MatchesRegex('/test/');
        $this->assertEquals('MatchesRegex', $assertion->getName());
    }
}
