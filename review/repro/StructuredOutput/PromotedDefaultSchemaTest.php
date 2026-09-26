<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\JsonSchema;
use PHPUnit\Framework\TestCase;

class PromotedDefaultSchemaTest extends TestCase
{
    public function test_promoted_property_with_default_is_optional_with_default(): void
    {
        $class = new class () {
            public function __construct(public string $title = 'untitled')
            {
            }
        };

        $schema = JsonSchema::make()->generate($class::class);

        $this->assertArrayNotHasKey('required', $schema);
        $this->assertSame('untitled', $schema['properties']['title']['default'] ?? null);
    }

    public function test_deserializer_fills_omitted_promoted_default(): void
    {
        $class = new class () {
            public function __construct(public string $title = 'untitled')
            {
            }
        };

        $obj = Deserializer::make()->fromJson('{}', $class::class);

        $this->assertSame('untitled', $obj->title);
    }

    public function test_promoted_default_after_required_parameter_stays_required(): void
    {
        $class = new class ('x') {
            public function __construct(public string $name, public string $title = 'untitled')
            {
            }
        };

        $schema = JsonSchema::make()->generate($class::class);
        $obj = Deserializer::make()->fromJson('{"name": "n"}', $class::class);

        $this->assertSame(['name', 'title'], $schema['required']);
        $this->assertFalse(isset($obj->title));
    }
}
