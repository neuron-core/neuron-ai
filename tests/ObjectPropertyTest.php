<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

/**
 * Test classes for complex nested structures
 */
class Address
{
    public string $street;
    public string $city;
    public string $zipCode;
    /** @var float[] */
    public array $coordinates;
}

class Contact
{
    public string $type;
    public string $value;
    public bool $isPrimary;
}

class Company
{
    public string $name;
    public Address $headquarters;
    /** @var Address[] */
    public array $offices;
}

class Person
{
    public string $name;
    public int $age;
    public Address $address;
    /** @var Contact[] */
    public array $contacts;
    /** @var string[] */
    public array $tags;
    public ?Company $company = null;
}

class NestedComplexStructure
{
    public string $id;
    /** @var Person[] */
    public array $people;
    /** @var array<string, Company> */
    public array $companies;
    public array $metadata; // Mixed array
}

class ObjectPropertyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the JsonSchema class behavior
        $this->mockJsonSchemaGeneration();
    }

    protected function mockJsonSchemaGeneration(): void
    {
        // This would need to be properly mocked in a real test environment
        // For this example, I'm showing the expected schema structures
    }

    public function testSimpleObjectPropertyCreation(): void
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

    public function testNestedObjectPropertyCreation(): void
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

    public function testArrayOfObjectsProperty(): void
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

    public function testComplexNestedStructure(): void
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

    public function testJsonSchemaGeneration(): void
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

    public function testDeepNestedArraysAndObjects(): void
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

        $this->assertEquals('deep_structure', $deepNestedProperty->getName());
        $this->assertCount(2, $deepNestedProperty->getProperties());

        // Navigate through the deep structure
        $properties = $deepNestedProperty->getProperties();
        $levelsArray = $properties[1];

        $this->assertInstanceOf(ArrayProperty::class, $levelsArray);
        $this->assertEquals('levels', $levelsArray->getName());

        $levelObject = $levelsArray->getItems();
        $this->assertInstanceOf(ObjectProperty::class, $levelObject);

        $levelProperties = $levelObject->getProperties();
        $itemsArray = $levelProperties[1];
        $this->assertInstanceOf(ArrayProperty::class, $itemsArray);

        $itemObject = $itemsArray->getItems();
        $this->assertInstanceOf(ObjectProperty::class, $itemObject);

        $itemProperties = $itemObject->getProperties();
        $propertiesArray = $itemProperties[1];
        $this->assertInstanceOf(ArrayProperty::class, $propertiesArray);

        $propertyItem = $propertiesArray->getItems();
        $this->assertInstanceOf(ToolProperty::class, $propertyItem);
        $this->assertEquals(PropertyType::STRING, $propertyItem->getType());
    }

    public function testRequiredPropertiesInNestedStructures(): void
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

    public function testJsonSerializationOfComplexStructure(): void
    {
        $property = $this->createSimpleNestedStructure();
        $serialized = $property->jsonSerialize();

        $this->assertArrayHasKey('name', $serialized);
        $this->assertArrayHasKey('description', $serialized);
        $this->assertArrayHasKey('type', $serialized);
        $this->assertArrayHasKey('properties', $serialized);
        $this->assertArrayHasKey('required', $serialized);

        $this->assertEquals(PropertyType::OBJECT, $serialized['type']);
        $this->assertTrue($serialized['required']);
    }

    // Helper methods for creating test structures

    private function createAddressProperty(): ObjectProperty
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

    private function createContactProperty(): ObjectProperty
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

    private function createCompanyProperty(ObjectProperty $addressProperty): ObjectProperty
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

    private function createSimpleNestedStructure(): ObjectProperty
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

    private function assertComplexPersonStructure(ObjectProperty $personProperty): void
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
}
