<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaPropertiesInterface;
use NeuronAI\StructuredOutput\SchemaProperty;
use PHPUnit\Framework\TestCase;

class InheritedSchemaPropertiesTest extends TestCase
{
    public function test_runtime_entry_applies_to_inherited_properties(): void
    {
        $schema = JsonSchema::make()->generate(ChildWithRuntimeProperties::class);

        $this->assertSame('Child description', $schema['properties']['name']['description'] ?? null);
    }

    public function test_child_override_of_schema_properties_wins(): void
    {
        $schema = JsonSchema::make()->generate(ChildOverridingRuntimeProperties::class);

        $this->assertSame('Child override', $schema['properties']['name']['description']);
    }

    public function test_deserializer_uses_child_runtime_item_class_for_inherited_array(): void
    {
        $object = (new Deserializer())->fromJson('{"name":"x","items":[{"label":"a"}]}', ChildWithRuntimeProperties::class);

        $this->assertInstanceOf(InheritedItem::class, $object->items[0]);
        $this->assertSame('a', $object->items[0]->label);
    }
}

class InheritedItem
{
    public string $label;
}

class PlainBase
{
    public string $name;

    public array $items;
}

class ChildWithRuntimeProperties extends PlainBase implements SchemaPropertiesInterface
{
    public static function schemaProperties(): array
    {
        return [
            'name' => new SchemaProperty(description: 'Child description'),
            'items' => new SchemaProperty(anyOf: [InheritedItem::class]),
        ];
    }
}

class BaseWithRuntimeProperties implements SchemaPropertiesInterface
{
    public string $name;

    public static function schemaProperties(): array
    {
        return ['name' => new SchemaProperty(description: 'Base description')];
    }
}

class ChildOverridingRuntimeProperties extends BaseWithRuntimeProperties
{
    public static function schemaProperties(): array
    {
        return ['name' => new SchemaProperty(description: 'Child override')];
    }
}
