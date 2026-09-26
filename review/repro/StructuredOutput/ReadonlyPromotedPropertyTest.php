<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Deserializer;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use PHPUnit\Framework\TestCase;

class ReadonlyPromotedPropertyTest extends TestCase
{
    public function test_readonly_promoted_property_with_default_is_deserialized(): void
    {
        $class = new class () {
            public function __construct(public readonly string $title = 'untitled')
            {
            }
        };

        $obj = Deserializer::make()->fromJson('{"title": "Hello"}', $class::class);

        $this->assertSame('Hello', $obj->title);
    }

    public function test_readonly_promoted_property_keeps_default_when_missing(): void
    {
        $class = new class () {
            public function __construct(public readonly string $title = 'untitled')
            {
            }
        };

        $obj = Deserializer::make()->fromJson('{}', $class::class);

        $this->assertSame('untitled', $obj->title);
    }
}
