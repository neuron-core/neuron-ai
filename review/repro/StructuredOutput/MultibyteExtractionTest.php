<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\JsonExtractor;
use PHPUnit\Framework\TestCase;

use function json_decode;

class MultibyteExtractionTest extends TestCase
{
    public function test_multibyte_prose_does_not_truncate_the_scan(): void
    {
        $this->assertSame('{"b":2}', (new JsonExtractor())->getJson('Ecco è {bozza} poi {"b":2}'));
    }

    public function test_multibyte_value_in_the_last_object_is_extracted(): void
    {
        $json = (new JsonExtractor())->getJson('{draft} then {"n":"Jürgen"}');

        $this->assertNotNull($json);
        $this->assertSame(['n' => 'Jürgen'], json_decode($json, true));
    }

    public function test_ascii_prose_with_draft_braces_extracts_the_trailing_object(): void
    {
        $this->assertSame('{"b":2}', (new JsonExtractor())->getJson('Ecco e {bozza} poi {"b":2}'));
    }
}
