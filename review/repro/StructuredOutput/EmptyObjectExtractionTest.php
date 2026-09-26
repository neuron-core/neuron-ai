<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\JsonExtractor;
use PHPUnit\Framework\TestCase;

class EmptyObjectExtractionTest extends TestCase
{
    public function test_empty_objects_are_preserved(): void
    {
        $this->assertSame('{"meta":{}}', (new JsonExtractor())->getJson('{"meta":{}}'));
        $this->assertSame('{}', (new JsonExtractor())->getJson('{}'));
    }
}
