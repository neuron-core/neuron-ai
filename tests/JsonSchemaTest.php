<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\Tests\Stubs\StructuredOutput\EmailMode;
use NeuronAI\Tests\Stubs\StructuredOutput\FtpMode;
use NeuronAI\Tests\Stubs\StructuredOutput\ImageBlock;
use NeuronAI\Tests\Stubs\StructuredOutput\Person;
use NeuronAI\Tests\Stubs\StructuredOutput\TextBlock;
use NeuronAI\Tests\Stubs\StructuredOutput\User;
use PHPUnit\Framework\TestCase;

class JsonSchemaTest extends TestCase
{
    public function test_all_properties_required(): void
    {
        $class = new class () {
            public string $firstName;
            public string $lastName;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'firstName' => [
                    'type' => 'string',
                ],
                'lastName' => [
                    'type' => 'string',
                ]
            ],
            'required' => ['firstName', 'lastName'],
            'additionalProperties' => false,
        ], $schema);
    }
    public function test_with_nullable_properties(): void
    {
        $class = new class () {
            public string $firstName;
            public ?string $lastName = null;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'firstName' => [
                    'type' => 'string',
                ],
                'lastName' => [
                    'type' => ['string', 'null'],
                    'default' => null
                ]
            ],
            'required' => ['firstName'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_with_default_value(): void
    {
        $class = new class () {
            public string $firstName;
            public ?string $lastName = 'last name';
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'firstName' => [
                    'type' => 'string',
                ],
                'lastName' => [
                    'default' => 'last name',
                    'type' => ['string', 'null'],
                ]
            ],
            'required' => ['firstName'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_with_attribute(): void
    {
        $class = new class () {
            #[SchemaProperty(title: "The user first name", description: "The user first name")]
            public string $firstName;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'firstName' => [
                    'title' => 'The user first name',
                    'description' => 'The user first name',
                    'type' => 'string',
                ]
            ],
            'required' => ['firstName'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_nullable_property_with_attribute(): void
    {
        $class = new class () {
            #[SchemaProperty(title: "The user first name", description: "The user first name", required: false)]
            public string $firstName;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'firstName' => [
                    'title' => 'The user first name',
                    'description' => 'The user first name',
                    'type' => 'string',
                ]
            ],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_nested_object(): void
    {
        $schema = (new JsonSchema())->generate(Person::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'firstName' => [
                    'type' => 'string',
                ],
                'lastName' => [
                    'type' => 'string',
                ],
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'street' => [
                            'description' => 'The name of the street',
                            'type' => 'string',
                        ],
                        'city' => [
                            'type' => 'string',
                        ],
                        'zip' => [
                            'description' => 'The zip code of the address',
                            'type' => 'string',
                        ]
                    ],
                    'required' => ['street', 'city', 'zip'],
                    'additionalProperties' => false,
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'description' => 'The name of the tag',
                                'type' => 'string',
                            ],
                            'properties' => [
                                'description' => 'Properties can contains additional values',
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'value' => [
                                            'description' => 'The property value',
                                            'type' => 'string',
                                        ]
                                    ],
                                    'additionalProperties' => false,
                                    'required' => ['value']
                                ]
                            ]
                        ],
                        'required' => ['name'],
                        'additionalProperties' => false,
                    ]
                ]
            ],
            'required' => ['firstName', 'lastName', 'address', 'tags'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_nested_object_with_alternative_syntax(): void
    {
        $schema = (new JsonSchema())->generate(Person::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'firstName' => [
                    'type' => 'string',
                ],
                'lastName' => [
                    'type' => 'string',
                ],
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'street' => [
                            'description' => 'The name of the street',
                            'type' => 'string',
                        ],
                        'city' => [
                            'type' => 'string',
                        ],
                        'zip' => [
                            'description' => 'The zip code of the address',
                            'type' => 'string',
                        ]
                    ],
                    'required' => ['street', 'city', 'zip'],
                    'additionalProperties' => false,
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'description' => 'The name of the tag',
                                'type' => 'string',
                            ],
                            'properties' => [
                                'description' => 'Properties can contains additional values',
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'value' => [
                                            'description' => 'The property value',
                                            'type' => 'string',
                                        ]
                                    ],
                                    'additionalProperties' => false,
                                    'required' => ['value']
                                ]
                            ]
                        ],
                        'required' => ['name'],
                        'additionalProperties' => false,
                    ]
                ]
            ],
            'required' => ['firstName', 'lastName', 'address', 'tags'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_array_of_object(): void
    {
        $people = new class () {
            #[SchemaProperty(description: "The list of users", required: true, anyOf: [User::class])]
            #[ArrayOf(User::class)]
            public array $people;
        };

        $schema = (new JsonSchema())->generate($people::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'people' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'The name of the user'
                            ]
                        ],
                        'required' => ['name'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'The list of users'
                ]
            ],
            'required' => ['people'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_array_with_multiple_types_using_anyof(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
            public array $modes;
        };

        $schema = (new JsonSchema())->generate($class::class);

        // Verify the structure has anyOf
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('modes', $schema['properties']);
        $this->assertEquals('array', $schema['properties']['modes']['type']);
        $this->assertArrayHasKey('items', $schema['properties']['modes']);
        $this->assertArrayHasKey('anyOf', $schema['properties']['modes']['items']);
        $this->assertCount(2, $schema['properties']['modes']['items']['anyOf']);

        // Verify each anyOf schema is an object with expected properties
        $schemas = $schema['properties']['modes']['items']['anyOf'];

        // First schema should be FtpMode with __classname__ discriminator
        $this->assertEquals('object', $schemas[0]['type']);
        $this->assertArrayHasKey('properties', $schemas[0]);
        $this->assertArrayHasKey('__classname__', $schemas[0]['properties']);
        $this->assertEquals(['ftpmode'], $schemas[0]['properties']['__classname__']['enum']);
        $this->assertArrayHasKey('mode', $schemas[0]['properties']);
        $this->assertArrayHasKey('account', $schemas[0]['properties']);
        $this->assertContains('__classname__', $schemas[0]['required']);

        // Second schema should be EmailMode with __classname__ discriminator
        $this->assertEquals('object', $schemas[1]['type']);
        $this->assertArrayHasKey('properties', $schemas[1]);
        $this->assertArrayHasKey('__classname__', $schemas[1]['properties']);
        $this->assertEquals(['emailmode'], $schemas[1]['properties']['__classname__']['enum']);
        $this->assertArrayHasKey('mode', $schemas[1]['properties']);
        $this->assertArrayHasKey('mailingList', $schemas[1]['properties']);
        $this->assertContains('__classname__', $schemas[1]['required']);
    }

    public function test_array_with_multiple_types_using_anyof_alternative(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [ImageBlock::class, TextBlock::class])]
            public array $blocks;
        };

        $schema = (new JsonSchema())->generate($class::class);

        // Verify the structure has anyOf
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('blocks', $schema['properties']);
        $this->assertEquals('array', $schema['properties']['blocks']['type']);
        $this->assertArrayHasKey('items', $schema['properties']['blocks']);
        $this->assertArrayHasKey('anyOf', $schema['properties']['blocks']['items']);
        $this->assertCount(2, $schema['properties']['blocks']['items']['anyOf']);

        // Verify each anyOf schema is an object with expected properties
        $schemas = $schema['properties']['blocks']['items']['anyOf'];

        // The first schema should be ImageBlock with __classname__ discriminator
        $this->assertEquals('object', $schemas[0]['type']);
        $this->assertArrayHasKey('properties', $schemas[0]);
        $this->assertArrayHasKey('__classname__', $schemas[0]['properties']);
        $this->assertEquals(['imageblock'], $schemas[0]['properties']['__classname__']['enum']);
        $this->assertArrayHasKey('type', $schemas[0]['properties']);
        $this->assertArrayHasKey('url', $schemas[0]['properties']);
        $this->assertContains('__classname__', $schemas[0]['required']);

        // The second schema should be TextBlock with __classname__ discriminator
        $this->assertEquals('object', $schemas[1]['type']);
        $this->assertArrayHasKey('properties', $schemas[1]);
        $this->assertArrayHasKey('__classname__', $schemas[1]['properties']);
        $this->assertEquals(['textblock'], $schemas[1]['properties']['__classname__']['enum']);
        $this->assertArrayHasKey('type', $schemas[1]['properties']);
        $this->assertArrayHasKey('content', $schemas[1]['properties']);
        $this->assertContains('__classname__', $schemas[1]['required']);
    }

    public function test_string_constraints(): void
    {
        $class = new class () {
            #[SchemaProperty(description: "A title", minLength: 1, maxLength: 100)]
            public string $title;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'title' => [
                    'description' => 'A title',
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 100,
                ]
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_numeric_constraints(): void
    {
        $class = new class () {
            #[SchemaProperty(description: "A rating", min: 1, max: 5)]
            public int $rating;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'rating' => [
                    'description' => 'A rating',
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 5,
                ]
            ],
            'required' => ['rating'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_array_constraints(): void
    {
        $class = new class () {
            #[SchemaProperty(description: "Tags list", min: 1, max: 10)]
            public array $tags;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'description' => 'Tags list',
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                    'maxItems' => 10,
                ]
            ],
            'required' => ['tags'],
            'additionalProperties' => false,
        ], $schema);
    }
}
