<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Properties;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

class NullablePropertyTest extends TestCase
{
    public function test_tool_property_non_nullable_by_default(): void
    {
        $property = new ToolProperty('name', PropertyType::STRING, 'A name');

        $this->assertFalse($property->isNullable());
        $this->assertSame('string', $property->getJsonSchema()['type']);
    }

    public function test_tool_property_nullable_emits_array_type(): void
    {
        $property = new ToolProperty('name', PropertyType::STRING, 'A name', false, [], true);

        $this->assertTrue($property->isNullable());

        $schema = $property->getJsonSchema();
        $this->assertSame(['string', 'null'], $schema['type']);
    }

    public function test_tool_property_nullable_with_description(): void
    {
        $property = new ToolProperty('name', PropertyType::INTEGER, 'Count', false, [], true);

        $schema = $property->getJsonSchema();
        $this->assertSame(['integer', 'null'], $schema['type']);
        $this->assertSame('Count', $schema['description']);
    }

    public function test_tool_property_nullable_with_enum(): void
    {
        $property = new ToolProperty(
            'status',
            PropertyType::STRING,
            'Status',
            false,
            ['active', 'inactive'],
            true,
        );

        $schema = $property->getJsonSchema();
        $this->assertSame(['string', 'null'], $schema['type']);
        $this->assertSame(['active', 'inactive'], $schema['enum']);
    }

    public function test_tool_property_make_with_nullable(): void
    {
        $property = ToolProperty::make('name', PropertyType::STRING, 'A name', false, [], true);

        $this->assertTrue($property->isNullable());
        $this->assertSame(['string', 'null'], $property->getJsonSchema()['type']);
    }

    public function test_array_property_nullable(): void
    {
        $property = new ArrayProperty('items', 'List of items', false, null, null, null, true);

        $this->assertTrue($property->isNullable());

        $schema = $property->getJsonSchema();
        $this->assertSame(['array', 'null'], $schema['type']);
    }

    public function test_array_property_non_nullable_by_default(): void
    {
        $property = new ArrayProperty('items', 'List of items');

        $this->assertFalse($property->isNullable());
        $this->assertSame('array', $property->getJsonSchema()['type']);
    }

    public function test_object_property_nullable(): void
    {
        $property = new ObjectProperty('config', 'Config object', false, null, [], true);

        $this->assertTrue($property->isNullable());

        $schema = $property->getJsonSchema();
        $this->assertSame(['object', 'null'], $schema['type']);
    }

    public function test_object_property_non_nullable_by_default(): void
    {
        $property = new ObjectProperty('config', 'Config object');

        $this->assertFalse($property->isNullable());
        $this->assertSame('object', $property->getJsonSchema()['type']);
    }

    public function test_object_property_with_nullable_children(): void
    {
        $property = new ObjectProperty(
            'user',
            'User object',
            true,
            null,
            [
                new ToolProperty('name', PropertyType::STRING, 'Name', true),
                new ToolProperty('email', PropertyType::STRING, 'Email', false, [], true),
            ],
        );

        $schema = $property->getJsonSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame(['string', 'null'], $schema['properties']['email']['type']);
    }

    public function test_json_serialize_includes_nullable(): void
    {
        $property = new ToolProperty('name', PropertyType::STRING, 'A name', false, [], true);

        $serialized = $property->jsonSerialize();
        $this->assertTrue($serialized['nullable']);
    }
}
