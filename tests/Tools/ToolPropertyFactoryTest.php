<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ToolPropertyFactoryTest extends TestCase
{
    public function test_builds_scalar_properties_with_their_metadata(): void
    {
        $properties = ToolPropertyFactory::fromSchema([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Display name', 'enum' => ['Alice', 'Bob']],
                'age' => ['type' => ['null', 'integer']],
                'score' => ['type' => 'number'],
                'active' => ['type' => 'boolean'],
            ],
            'required' => ['name', 'age'],
        ]);

        $this->assertCount(4, $properties);
        $this->assertInstanceOf(ToolProperty::class, $properties[0]);
        $this->assertSame('name', $properties[0]->getName());
        $this->assertSame('Display name', $properties[0]->getDescription());
        $this->assertSame(['Alice', 'Bob'], $properties[0]->getEnum());
        $this->assertTrue($properties[0]->isRequired());
        $this->assertFalse($properties[0]->isNullable());
        $this->assertSame(PropertyType::INTEGER, $properties[1]->getType());
        $this->assertTrue($properties[1]->isRequired());
        $this->assertTrue($properties[1]->isNullable());
        $this->assertSame(PropertyType::NUMBER, $properties[2]->getType());
        $this->assertFalse($properties[2]->isRequired());
        $this->assertSame(PropertyType::BOOLEAN, $properties[3]->getType());
    }

    public function test_recursively_builds_objects_array_items_and_required_flags(): void
    {
        $properties = ToolPropertyFactory::fromSchema([
            'properties' => [
                'selection' => [
                    'type' => ['object', 'null'],
                    'description' => 'Selected entries',
                    'properties' => [
                        'entries' => [
                            'type' => ['array', 'null'],
                            'description' => 'Entries',
                            'minItems' => 1,
                            'maxItems' => 3,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'labels' => [
                                        'type' => 'array',
                                        'items' => ['type' => ['string', 'null'], 'enum' => ['primary', 'secondary']],
                                    ],
                                ],
                                'required' => ['id'],
                            ],
                        ],
                    ],
                    'required' => ['entries'],
                ],
            ],
        ]);

        $selection = $properties[0];
        $this->assertInstanceOf(ObjectProperty::class, $selection);
        $this->assertNull($selection->getClass());
        $this->assertTrue($selection->isNullable());
        $this->assertFalse($selection->isRequired());
        $this->assertSame('Selected entries', $selection->getDescription());
        $entries = $selection->getProperties()[0];
        $this->assertInstanceOf(ArrayProperty::class, $entries);
        $this->assertTrue($entries->isRequired());
        $this->assertTrue($entries->isNullable());
        $this->assertSame(1, $entries->getJsonSchema()['minItems']);
        $this->assertSame(3, $entries->getJsonSchema()['maxItems']);
        $item = $entries->getItems();
        $this->assertInstanceOf(ObjectProperty::class, $item);
        $this->assertFalse($item->isRequired());
        $this->assertSame(['id'], $item->getRequiredProperties());
        $this->assertSame(PropertyType::INTEGER, $item->getProperties()[0]->getType());
        $labels = $item->getProperties()[1];
        $this->assertInstanceOf(ArrayProperty::class, $labels);
        $label = $labels->getItems();
        $this->assertInstanceOf(ToolProperty::class, $label);
        $this->assertTrue($label->isNullable());
        $this->assertSame(['primary', 'secondary'], $label->getEnum());
    }

    public function test_supports_arrays_of_arrays(): void
    {
        $properties = ToolPropertyFactory::fromSchema([
            'properties' => ['matrix' => [
                'type' => 'array',
                'items' => ['type' => 'array', 'items' => ['type' => 'number']],
            ]],
        ]);
        $matrix = $properties[0];
        $this->assertInstanceOf(ArrayProperty::class, $matrix);
        $row = $matrix->getItems();
        $this->assertInstanceOf(ArrayProperty::class, $row);
        $this->assertSame(PropertyType::NUMBER, $row->getItems()->getType());
    }

    public function test_empty_schemas_and_untyped_properties_follow_existing_defaults(): void
    {
        $this->assertSame([], ToolPropertyFactory::fromSchema([]));
        $this->assertSame([], ToolPropertyFactory::fromSchema(['type' => 'object', 'properties' => []]));
        $properties = ToolPropertyFactory::fromSchema(['properties' => ['value' => [], 'values' => ['type' => 'array']]]);
        $this->assertSame(PropertyType::STRING, $properties[0]->getType());
        $this->assertSame(['type' => 'string'], $properties[1]->getJsonSchema()['items']);
    }

    /** @param array<string, mixed> $definition */
    #[DataProvider('unsupportedSchemas')]
    public function test_rejects_shapes_the_property_model_cannot_represent(array $definition, string $message): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage($message);

        ToolPropertyFactory::fromSchema(['properties' => ['value' => $definition]]);
    }

    public static function unsupportedSchemas(): array
    {
        return [
            'reference' => [['$ref' => '#/$defs/value'], "JSON Schema keyword '\$ref' cannot be represented by the tool property types."],
            'union' => [['oneOf' => [['type' => 'string'], ['type' => 'integer']]], "JSON Schema keyword 'oneOf' cannot be represented by the tool property types."],
            'anyOf' => [['anyOf' => [['type' => 'string'], ['type' => 'null']]], "JSON Schema keyword 'anyOf' cannot be represented by the tool property types."],
            'intersection' => [['allOf' => [['type' => 'object']]], "JSON Schema keyword 'allOf' cannot be represented by the tool property types."],
            'tuple' => [['type' => 'array', 'prefixItems' => [['type' => 'string']]], "JSON Schema keyword 'prefixItems' cannot be represented by the tool property types."],
            'legacy tuple' => [['type' => 'array', 'items' => [['type' => 'string']]], 'Tuple schemas cannot be represented by a single array item property.'],
            'multiple types' => [['type' => ['string', 'integer']], "Property 'value' must declare one non-null type."],
            'only null' => [['type' => ['null']], "Property 'value' must declare one non-null type."],
            'null as the type' => [['type' => 'null'], "Unsupported type 'null' for property 'value'."],
            'unknown type' => [['type' => 'unknown'], "Unsupported type 'unknown' for property 'value'."],
            'nested reference' => [['type' => 'array', 'items' => ['$ref' => '#/$defs/value']], "JSON Schema keyword '\$ref' cannot be represented by the tool property types."],
            'deeply nested union' => [
                ['type' => 'object', 'properties' => ['inner' => ['type' => 'object', 'properties' => ['leaf' => ['anyOf' => []]]]]],
                "JSON Schema keyword 'anyOf' cannot be represented by the tool property types.",
            ],
        ];
    }

    public function test_rejects_a_top_level_union(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("JSON Schema keyword 'oneOf' cannot be represented by the tool property types.");

        ToolPropertyFactory::fromSchema(['oneOf' => [['type' => 'object']]]);
    }

    public function test_array_constraints_are_validated(): void
    {
        $this->expectException(ArrayPropertyException::class);
        $this->expectExceptionMessage('minItems (3) cannot be greater than maxItems (1)');

        ToolPropertyFactory::fromSchema(['properties' => ['values' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 1]]]);
    }

    public function test_supported_schemas_round_trip_through_the_property_model(): void
    {
        $properties = [
            'query' => ['type' => 'string', 'description' => 'Search terms'],
            'mode' => ['type' => ['string', 'null'], 'enum' => ['fast', 'deep']],
            'limit' => ['type' => 'integer'],
            'filters' => [
                'type' => 'object',
                'description' => 'Narrow the search',
                'properties' => [
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 5],
                    'range' => [
                        'type' => ['object', 'null'],
                        'properties' => ['from' => ['type' => 'number'], 'to' => ['type' => 'number']],
                        'required' => ['from'],
                    ],
                ],
                'required' => [],
            ],
            'points' => [
                'type' => ['array', 'null'],
                'items' => ['type' => 'array', 'items' => ['type' => 'boolean']],
            ],
        ];

        $root = new ObjectProperty('root', properties: ToolPropertyFactory::fromSchema([
            'type' => 'object',
            'properties' => $properties,
            'required' => ['query', 'points'],
        ]));

        $this->assertSame(
            ['type' => 'object', 'properties' => $properties, 'required' => ['query', 'points']],
            $root->getJsonSchema()
        );
    }

    public function test_required_names_are_matched_exactly(): void
    {
        $properties = ToolPropertyFactory::fromSchema([
            'properties' => ['Name' => ['type' => 'string'], 'name' => ['type' => 'string']],
            'required' => ['name', 'missing'],
        ]);

        $this->assertSame([false, true], [$properties[0]->isRequired(), $properties[1]->isRequired()]);
    }

    public function test_numeric_looking_required_names_are_compared_as_strings(): void
    {
        $properties = ToolPropertyFactory::fromSchema([
            'properties' => ['1.0' => ['type' => 'string'], '1e1' => ['type' => 'string']],
            'required' => ['1', '10'],
        ]);

        $this->assertSame([false, false], [$properties[0]->isRequired(), $properties[1]->isRequired()]);
    }
}
