<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

class ArrayPropertyTest extends TestCase
{
    public function test_json_schema_contains_min_and_max_items(): void
    {
        $arrayProp = new ArrayProperty(
            name :"array_prop",
            description: "array prop description",
            required: true,
            items: new ToolProperty(
                name :"array_item",
                type: PropertyType::STRING,
                description: "array item description",
                required: true,
            ),
            minItems: 1,
            maxItems: 10
        );

        $expectedJsonSchema = [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'description' => 'array item description',
            ],
            'minItems' => 1,
            'maxItems' => 10,
            'description' => "array prop description",
        ];

        $this->assertEquals($expectedJsonSchema, $arrayProp->getJsonSchema());
    }

    public function test_min_items_equals_max_items_is_valid(): void
    {
        $arrayProp = new ArrayProperty(
            name :"array_prop",
            description: "array prop description",
            required: true,
            items: new ToolProperty(
                name :"array_item",
                type: PropertyType::STRING,
                description: "array item description",
                required: true,
            ),
            minItems: 5,
            maxItems: 5
        );

        $expectedJsonSchema = [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'description' => 'array item description',
            ],
            'minItems' => 5,
            'maxItems' => 5,
            'description' => "array prop description",
        ];

        $this->assertEquals($expectedJsonSchema, $arrayProp->getJsonSchema());
    }

    public function test_array_property_min_and_max_items_null(): void
    {
        $arrayProp = new ArrayProperty(
            name: "array_prop",
            description: "array prop description",
            items: new ToolProperty(
                name: "array_item",
                type: PropertyType::STRING,
                description: "array item description",
                required: true,
            ),
        );

        $schema = $arrayProp->getJsonSchema();

        $this->assertArrayNotHasKey('minItems', $schema);
        $this->assertArrayNotHasKey('maxItems', $schema);
    }

    public function test_min_items_cannot_be_negative(): void
    {
        $this->expectException(ArrayPropertyException::class);
        $this->expectExceptionMessage('minItems must be >= 0, got -1');

        new ArrayProperty(
            name :"array_prop",
            description: "array prop description",
            items: new ToolProperty(
                name :"array_item",
                type: PropertyType::STRING,
                description: "array item description",
                required: true,
            ),
            minItems: -1,
            maxItems: 10
        );
    }

    public function test_max_items_cannot_be_negative(): void
    {
        $this->expectException(ArrayPropertyException::class);
        $this->expectExceptionMessage('maxItems must be >= 0, got -1');

        new ArrayProperty(
            name :"array_prop",
            description: "array prop description",
            items: new ToolProperty(
                name :"array_item",
                type: PropertyType::STRING,
                description: "array item description",
                required: true,
            ),
            minItems: 0,
            maxItems: -1
        );
    }

    public function test_min_items_cannot_be_greater_than_max_items(): void
    {
        $this->expectException(ArrayPropertyException::class);
        $this->expectExceptionMessage('minItems (10) cannot be greater than maxItems (9)');

        new ArrayProperty(
            name :"array_prop",
            description: "array prop description",
            items: new ToolProperty(
                name :"array_item",
                type: PropertyType::STRING,
                description: "array item description",
                required: true,
            ),
            minItems: 10,
            maxItems: 9
        );
    }

    public function test_cast_converts_each_element_through_the_items_property(): void
    {
        $property = new ArrayProperty('numbers', items: new ToolProperty('number', PropertyType::INTEGER));

        $this->assertSame([12, 18], $property->cast([12, '18']));
        $this->assertNull($property->cast(null));
    }

    public function test_cast_names_the_failing_element(): void
    {
        $property = new ArrayProperty('numbers', items: new ToolProperty('number', PropertyType::INTEGER));

        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage('element 1 must be of type integer, string given');

        $property->cast([12, 'a']);
    }

    public function test_cast_rejects_a_non_array(): void
    {
        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage('must be of type array, string given');

        (new ArrayProperty('numbers'))->cast('12, 18');
    }
}
