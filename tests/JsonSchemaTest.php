<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Tests\Stubs\StructuredOutput\Person;
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
}
