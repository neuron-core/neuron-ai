<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
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

    public function test_items_default_to_strings(): void
    {
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], (new ArrayProperty('tags'))->getJsonSchema());
    }

    public function test_zero_min_items_is_the_default_and_not_emitted(): void
    {
        $this->assertArrayNotHasKey('minItems', (new ArrayProperty('tags', minItems: 0, maxItems: 3))->getJsonSchema());
    }

    public function test_single_item_bounds_are_emitted(): void
    {
        $schema = (new ArrayProperty('choice', minItems: 1, maxItems: 1))->getJsonSchema();

        $this->assertSame(1, $schema['minItems']);
        $this->assertSame(1, $schema['maxItems']);
    }

    public function test_array_of_objects_schema(): void
    {
        $property = new ArrayProperty(
            'points',
            'The points to plot',
            true,
            new ObjectProperty('point', null, false, null, [
                new ToolProperty('x', PropertyType::NUMBER, required: true),
                new ToolProperty('label', PropertyType::STRING, nullable: true),
            ]),
            maxItems: 100,
            nullable: true,
        );

        $this->assertSame([
            'type' => ['array', 'null'],
            'description' => 'The points to plot',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'x' => ['type' => 'number'],
                    'label' => ['type' => ['string', 'null']],
                ],
                'required' => ['x'],
            ],
            'maxItems' => 100,
        ], $property->getJsonSchema());
    }

    public function test_json_serialization_describes_the_whole_property(): void
    {
        $property = new ArrayProperty('ids', 'Identifiers', true, new ToolProperty('id', PropertyType::INTEGER), 1, 5);

        $this->assertSame([
            'name' => 'ids',
            'type' => 'array',
            'description' => 'Identifiers',
            'items' => ['type' => 'integer'],
            'minItems' => 1,
            'maxItems' => 5,
            'required' => true,
            'nullable' => false,
        ], $property->jsonSerialize());
    }

    public function test_cast_without_items_keeps_elements_untouched(): void
    {
        $input = ['a', 1, true, null, ['nested']];

        $this->assertSame($input, (new ArrayProperty('values'))->cast($input));
    }

    public function test_cast_keeps_keys_and_names_them_in_errors(): void
    {
        $property = new ArrayProperty('scores', items: new ToolProperty('score', PropertyType::NUMBER));

        $this->assertSame(['alice' => 1.5, 'bob' => 2], $property->cast(['alice' => '1.5', 'bob' => '2']));

        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage('element bob must be of type number, string given');

        $property->cast(['alice' => 1, 'bob' => 'high']);
    }

    public function test_cast_recurses_into_nested_arrays(): void
    {
        $matrix = new ArrayProperty('matrix', items: new ArrayProperty('row', items: new ToolProperty('cell', PropertyType::INTEGER)));

        $this->assertSame([[1, 2], [3, 4]], $matrix->cast([['1', 2], [3.0, '4']]));

        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage('element 1 element 0 must be of type integer, string given');

        $matrix->cast([[1], ['x']]);
    }

    public function test_cast_keeps_null_elements(): void
    {
        $property = new ArrayProperty('values', items: new ToolProperty('value', PropertyType::INTEGER, nullable: true));

        $this->assertSame([1, null], $property->cast(['1', null]));
    }

    public function test_cast_rejects_a_json_object_string(): void
    {
        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage('must be of type array, string given');

        (new ArrayProperty('numbers', items: new ToolProperty('n', PropertyType::INTEGER)))->cast('[1, 2]');
    }
}
