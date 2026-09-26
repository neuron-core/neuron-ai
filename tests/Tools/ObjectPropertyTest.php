<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tests\Tools\Stub\Address;
use NeuronAI\Tests\Tools\Stub\Company;
use NeuronAI\Tests\Tools\Stub\Contact;
use NeuronAI\Tests\StructuredOutput\Stub\Person as StructuredPerson;
use NeuronAI\Tests\Tools\Stub\Person;
use NeuronAI\Tests\Tools\Stub\Ticket;
use NeuronAI\Tests\Tools\Stub\TicketPriority;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function json_encode;

class ObjectPropertyTest extends TestCase
{
    public function test_simple_object_property_creation(): void
    {
        $property = new ObjectProperty(
            'simple_object',
            'A simple object property',
            true,
            null,
            [
                new ToolProperty('name', PropertyType::STRING, 'The name', true),
                new ToolProperty('age', PropertyType::INTEGER, 'The age', false),
            ]
        );

        $this->assertEquals('simple_object', $property->getName());
        $this->assertEquals('A simple object property', $property->getDescription());
        $this->assertTrue($property->isRequired());
        $this->assertEquals(PropertyType::OBJECT, $property->getType());
        $this->assertCount(2, $property->getProperties());
        $this->assertEquals(['name'], $property->getRequiredProperties());
    }

    public function test_nested_object_property_creation(): void
    {
        // Create a nested address object
        $addressProperty = new ObjectProperty(
            'address',
            'Address information',
            true,
            null,
            [
                new ToolProperty('street', PropertyType::STRING, 'Street address', true),
                new ToolProperty('city', PropertyType::STRING, 'City name', true),
                new ToolProperty('zipCode', PropertyType::STRING, 'ZIP code', false),
                new ArrayProperty(
                    'coordinates',
                    'GPS coordinates',
                    false,
                    new ToolProperty('coordinate', PropertyType::NUMBER, 'A coordinate value')
                ),
            ]
        );

        // Create a person object with nested address
        $personProperty = new ObjectProperty(
            'person',
            'Person information',
            true,
            null,
            [
                new ToolProperty('name', PropertyType::STRING, 'Full name', true),
                new ToolProperty('age', PropertyType::INTEGER, 'Age in years', true),
                $addressProperty,
            ]
        );

        $this->assertEquals('person', $personProperty->getName());
        $this->assertCount(3, $personProperty->getProperties());

        // Check nested address property
        $properties = $personProperty->getProperties();
        $nestedAddress = $properties[2];

        $this->assertInstanceOf(ObjectProperty::class, $nestedAddress);
        $this->assertEquals('address', $nestedAddress->getName());
        $this->assertCount(4, $nestedAddress->getProperties());
        $this->assertEquals(['street', 'city'], $nestedAddress->getRequiredProperties());
    }

    public function test_array_of_objects_property(): void
    {
        // Create a contact object for array items
        $contactProperty = new ObjectProperty(
            'contact_item',
            'Contact information',
            false,
            null,
            [
                new ToolProperty('type', PropertyType::STRING, 'Contact type', true),
                new ToolProperty('value', PropertyType::STRING, 'Contact value', true),
                new ToolProperty('isPrimary', PropertyType::BOOLEAN, 'Is primary contact', false),
            ]
        );

        // Create array of contacts
        $contactsArrayProperty = new ArrayProperty(
            'contacts',
            'List of contacts',
            false,
            $contactProperty,
            1,
            10
        );

        $this->assertEquals('contacts', $contactsArrayProperty->getName());
        $this->assertEquals(PropertyType::ARRAY, $contactsArrayProperty->getType());

        $items = $contactsArrayProperty->getItems();
        $this->assertInstanceOf(ObjectProperty::class, $items);
        $this->assertEquals('contact_item', $items->getName());
        $this->assertCount(3, $items->getProperties());
    }

    public function test_complex_nested_structure(): void
    {
        // Build a complex nested structure manually to test deep nesting
        $addressProperty = $this->createAddressProperty();
        $contactProperty = $this->createContactProperty();
        $companyProperty = $this->createCompanyProperty($addressProperty);

        $personProperty = new ObjectProperty(
            'person',
            'Person with complex nested data',
            true,
            null,
            [
                new ToolProperty('name', PropertyType::STRING, 'Full name', true),
                new ToolProperty('age', PropertyType::INTEGER, 'Age', true),
                $addressProperty,
                new ArrayProperty('contacts', 'Contact list', false, $contactProperty),
                new ArrayProperty(
                    'tags',
                    'Tags',
                    false,
                    new ToolProperty('tag', PropertyType::STRING, 'A tag')
                ),
                $companyProperty,
            ]
        );

        $this->assertComplexPersonStructure($personProperty);
    }

    public function test_json_schema_generation(): void
    {
        $property = new ObjectProperty(
            'test_object',
            'Test object for schema generation',
            true,
            null,
            [
                new ToolProperty('id', PropertyType::STRING, 'Identifier', true),
                new ObjectProperty('metadata', 'Metadata object', false, null, [
                    new ToolProperty('version', PropertyType::STRING, 'Version', false),
                    new ToolProperty('timestamp', PropertyType::INTEGER, 'Timestamp', false),
                ]),
            ]
        );

        $schema = $property->getJsonSchema();

        $expectedSchema = [
            'type' => 'object',
            'description' => 'Test object for schema generation',
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'description' => 'Identifier',
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Metadata object',
                    'properties' => [
                        'version' => [
                            'type' => 'string',
                            'description' => 'Version',
                        ],
                        'timestamp' => [
                            'type' => 'integer',
                            'description' => 'Timestamp',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            'required' => ['id'],
        ];

        $this->assertEquals($expectedSchema, $schema);
    }

    public function test_deep_nested_arrays_and_objects(): void
    {
        // Test arrays containing objects containing arrays containing objects
        $deepNestedProperty = new ObjectProperty(
            'deep_structure',
            'Very deep nested structure',
            true,
            null,
            [
                new ToolProperty('id', PropertyType::STRING, 'ID', true),
                new ArrayProperty(
                    'levels',
                    'Multiple levels',
                    false,
                    new ObjectProperty(
                        'level',
                        'A level object',
                        false,
                        null,
                        [
                            new ToolProperty('name', PropertyType::STRING, 'Level name', true),
                            new ArrayProperty(
                                'items',
                                'Items in level',
                                false,
                                new ObjectProperty(
                                    'item',
                                    'An item',
                                    false,
                                    null,
                                    [
                                        new ToolProperty('value', PropertyType::STRING, 'Item value', true),
                                        new ArrayProperty(
                                            'properties',
                                            'Item properties',
                                            false,
                                            new ToolProperty('property', PropertyType::STRING, 'A property')
                                        ),
                                    ]
                                )
                            ),
                        ]
                    )
                ),
            ]
        );

        $this->assertSame([
            'type' => 'object',
            'description' => 'Very deep nested structure',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'ID'],
                'levels' => [
                    'type' => 'array',
                    'description' => 'Multiple levels',
                    'items' => [
                        'type' => 'object',
                        'description' => 'A level object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Level name'],
                            'items' => [
                                'type' => 'array',
                                'description' => 'Items in level',
                                'items' => [
                                    'type' => 'object',
                                    'description' => 'An item',
                                    'properties' => [
                                        'value' => ['type' => 'string', 'description' => 'Item value'],
                                        'properties' => [
                                            'type' => 'array',
                                            'description' => 'Item properties',
                                            'items' => ['type' => 'string', 'description' => 'A property'],
                                        ],
                                    ],
                                    'required' => ['value'],
                                ],
                            ],
                        ],
                        'required' => ['name'],
                    ],
                ],
            ],
            'required' => ['id'],
        ], $deepNestedProperty->getJsonSchema());
    }

    public function test_required_properties_in_nested_structures(): void
    {
        $nestedProperty = new ObjectProperty(
            'nested_required_test',
            'Test required properties in nested structures',
            true,
            null,
            [
                new ToolProperty('required_field', PropertyType::STRING, 'Required field', true),
                new ToolProperty('optional_field', PropertyType::STRING, 'Optional field', false),
                new ObjectProperty(
                    'nested_object',
                    'Nested object',
                    true,
                    null,
                    [
                        new ToolProperty('nested_required', PropertyType::STRING, 'Nested required', true),
                        new ToolProperty('nested_optional', PropertyType::STRING, 'Nested optional', false),
                    ]
                ),
                new ArrayProperty(
                    'array_field',
                    'Array field',
                    false,
                    new ObjectProperty(
                        'array_item',
                        'Array item',
                        false,
                        null,
                        [
                            new ToolProperty('item_required', PropertyType::STRING, 'Item required', true),
                        ]
                    )
                ),
            ]
        );

        // Test root level required properties
        $rootRequired = $nestedProperty->getRequiredProperties();
        $this->assertEquals(['required_field', 'nested_object'], $rootRequired);

        // Test nested object required properties
        $properties = $nestedProperty->getProperties();
        $nestedObject = $properties[2];
        $nestedRequired = $nestedObject->getRequiredProperties();
        $this->assertEquals(['nested_required'], $nestedRequired);

        // Test array item required properties
        $arrayProperty = $properties[3];
        $arrayItem = $arrayProperty->getItems();
        $arrayItemRequired = $arrayItem->getRequiredProperties();
        $this->assertEquals(['item_required'], $arrayItemRequired);
    }

    public function test_json_serialization_of_complex_structure(): void
    {
        $property = $this->createSimpleNestedStructure();
        $serialized = $property->jsonSerialize();

        $this->assertSame('simple_nested', $serialized['name']);
        $this->assertSame('Simple nested structure', $serialized['description']);
        $this->assertSame(PropertyType::OBJECT, $serialized['type']);
        $this->assertTrue($serialized['required']);
        $this->assertSame(['id'], $serialized['properties']['required']);
    }

    // Helper methods for creating test structures

    protected function createAddressProperty(): ObjectProperty
    {
        return new ObjectProperty(
            'address',
            'Address information',
            true,
            null,
            [
                new ToolProperty('street', PropertyType::STRING, 'Street', true),
                new ToolProperty('city', PropertyType::STRING, 'City', true),
                new ToolProperty('zipCode', PropertyType::STRING, 'ZIP', false),
                new ArrayProperty(
                    'coordinates',
                    'Coordinates',
                    false,
                    new ToolProperty('coordinate', PropertyType::NUMBER, 'Coordinate')
                ),
            ]
        );
    }

    protected function createContactProperty(): ObjectProperty
    {
        return new ObjectProperty(
            'contact',
            'Contact information',
            false,
            null,
            [
                new ToolProperty('type', PropertyType::STRING, 'Contact type', true),
                new ToolProperty('value', PropertyType::STRING, 'Contact value', true),
                new ToolProperty('isPrimary', PropertyType::BOOLEAN, 'Is primary', false),
            ]
        );
    }

    protected function createCompanyProperty(ObjectProperty $addressProperty): ObjectProperty
    {
        return new ObjectProperty(
            'company',
            'Company information',
            false,
            null,
            [
                new ToolProperty('name', PropertyType::STRING, 'Company name', true),
                clone $addressProperty, // headquarters
                new ArrayProperty('offices', 'Office locations', false, clone $addressProperty),
            ]
        );
    }

    protected function createSimpleNestedStructure(): ObjectProperty
    {
        return new ObjectProperty(
            'simple_nested',
            'Simple nested structure',
            true,
            null,
            [
                new ToolProperty('id', PropertyType::STRING, 'ID', true),
                new ObjectProperty(
                    'data',
                    'Data object',
                    false,
                    null,
                    [
                        new ToolProperty('value', PropertyType::STRING, 'Value', false),
                    ]
                ),
            ]
        );
    }

    protected function assertComplexPersonStructure(ObjectProperty $personProperty): void
    {
        $this->assertEquals('person', $personProperty->getName());
        $this->assertCount(6, $personProperty->getProperties());

        $properties = $personProperty->getProperties();

        // Check basic properties
        $this->assertInstanceOf(ToolProperty::class, $properties[0]);
        $this->assertEquals('name', $properties[0]->getName());
        $this->assertTrue($properties[0]->isRequired());

        // Check nested address
        $this->assertInstanceOf(ObjectProperty::class, $properties[2]);
        $this->assertEquals('address', $properties[2]->getName());

        // Check the contact array
        $this->assertInstanceOf(ArrayProperty::class, $properties[3]);
        $this->assertEquals('contacts', $properties[3]->getName());

        // Check the tags array (simple strings)
        $this->assertInstanceOf(ArrayProperty::class, $properties[4]);
        $this->assertEquals('tags', $properties[4]->getName());

        // Check company (optional nested object)
        $this->assertInstanceOf(ObjectProperty::class, $properties[5]);
        $this->assertEquals('company', $properties[5]->getName());
        $this->assertFalse($properties[5]->isRequired());
    }

    public function test_object_without_properties_emits_only_its_type(): void
    {
        $this->assertSame(['type' => 'object'], (new ObjectProperty('payload'))->getJsonSchema());
    }

    public function test_required_properties_are_a_list(): void
    {
        $property = new ObjectProperty('filter', properties: [
            new ToolProperty('field', PropertyType::STRING),
            new ToolProperty('value', PropertyType::STRING, required: true),
        ]);

        $this->assertSame(['value'], $property->getRequiredProperties());
        $this->assertSame('["value"]', json_encode($property->getJsonSchema()['required']));
    }

    public function test_class_properties_are_converted_with_their_types_enums_and_nullability(): void
    {
        $property = new ObjectProperty('ticket', 'The ticket to open', true, Ticket::class);

        $address = [
            'type' => ['object', 'null'],
            'properties' => [
                'street' => ['type' => 'string'],
                'city' => ['type' => 'string'],
                'zipCode' => ['type' => 'string'],
                'coordinates' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['street', 'city', 'zipCode', 'coordinates'],
        ];

        $this->assertSame([
            'type' => 'object',
            'description' => 'The ticket to open',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'What needs to be done'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'high']],
                'estimate' => ['type' => ['integer', 'null']],
                'urgent' => ['type' => 'boolean'],
                'score' => ['type' => 'number'],
                'location' => $address,
                'watchers' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'isPrimary' => ['type' => 'boolean'],
                        ],
                        'required' => ['type', 'value', 'isPrimary'],
                    ],
                ],
            ],
            'required' => ['title', 'priority', 'urgent', 'score', 'watchers'],
        ], $property->getJsonSchema());
    }

    public function test_class_properties_keep_nested_descriptions_of_array_items(): void
    {
        $property = new ObjectProperty('person', null, true, StructuredPerson::class);

        $tags = $property->getJsonSchema()['properties']['tags'];

        $this->assertSame([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'The name of the tag'],
                    'properties' => [
                        'type' => 'array',
                        'description' => 'Properties can contains additional values',
                        'items' => [
                            'type' => 'object',
                            'properties' => ['value' => ['type' => 'string', 'description' => 'The property value']],
                            'required' => ['value'],
                        ],
                    ],
                ],
                'required' => ['name'],
            ],
        ], $tags);
    }

    public function test_explicit_properties_win_over_the_class(): void
    {
        $property = new ObjectProperty('ticket', class: Ticket::class, properties: [new ToolProperty('id', PropertyType::INTEGER, required: true)]);

        $this->assertSame(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']], $property->getJsonSchema());
        $this->assertSame(Ticket::class, $property->getClass());
    }

    public function test_cast_deserializes_into_the_mapped_class(): void
    {
        $ticket = (new ObjectProperty('ticket', class: Ticket::class))->cast([
            'title' => 'Fix the login',
            'priority' => 'high',
            'urgent' => true,
            'score' => 1.5,
            'location' => ['street' => 'Via Roma 1', 'city' => 'Rome', 'zipCode' => '00100', 'coordinates' => []],
            'watchers' => [['type' => 'email', 'value' => 'ops@example.com', 'isPrimary' => true]],
        ]);

        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertSame('Fix the login', $ticket->title);
        $this->assertSame(TicketPriority::High, $ticket->priority);
        $this->assertNull($ticket->estimate);
        $this->assertInstanceOf(Address::class, $ticket->location);
        $this->assertSame('Rome', $ticket->location->city);
        $this->assertInstanceOf(Contact::class, $ticket->watchers[0]);
        $this->assertSame('ops@example.com', $ticket->watchers[0]->value);
    }

    public function test_cast_without_a_class_keeps_the_input(): void
    {
        $input = ['street' => 'Via Roma 1', 'extra' => ['nested' => true]];

        $this->assertSame($input, (new ObjectProperty('address', properties: [new ToolProperty('street', PropertyType::STRING)]))->cast($input));
    }

    public function test_tool_binds_a_mapped_object_as_an_instance(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'open_ticket';

            protected function properties(): array
            {
                return [new ObjectProperty('ticket', required: true, class: Ticket::class)];
            }

            public function __invoke(Ticket $ticket): string
            {
                return "{$ticket->title} ({$ticket->priority->value})";
            }
        };

        $tool->setInputs(['ticket' => ['title' => 'Fix', 'priority' => 'low', 'urgent' => false, 'score' => 0, 'watchers' => []]])->execute();

        $this->assertSame('Fix (low)', $tool->getResult());
    }
}
