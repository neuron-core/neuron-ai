<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\JsonExtractor;
use PHPUnit\Framework\TestCase;

class StrayClosingBraceExtractionTest extends TestCase
{
    public function test_stray_closing_brace_in_prose_does_not_hide_later_objects(): void
    {
        $this->assertSame('{"a":1}', (new JsonExtractor())->getJson('{draft} smile :} {"a":1} {"b":2}'));
    }

    public function test_stray_closing_brace_before_any_object_does_not_hide_it(): void
    {
        $this->assertSame('{"a":1}', (new JsonExtractor())->getJson('{draft} } then {"a":1}'));
    }

    public function test_stray_closing_brace_in_prose_without_leading_brace_does_not_hide_objects(): void
    {
        $this->assertSame('{"a":1}', (new JsonExtractor())->getJson('Sure :} here is {"a":1} and also {"b":2}'));
    }
}
