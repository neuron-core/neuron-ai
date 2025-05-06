<?php

namespace NeuronAI\Tests;

use NeuronAI\StructuredOutput\ArrayOf;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Tests\Utils\PersonWithAddress;
use NeuronAI\Tests\Utils\PersonWithTags;
use NeuronAI\Tests\Utils\Tag;
use PHPUnit\Framework\TestCase;

class JsonSchemaTest extends TestCase
{
    public function test_all_properties_required()
    {
        $class = new class {
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
    public function test_with_nullable_properties()
    {
        $class = new class {
            public string $firstName;
            public ?string $lastName;
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
                ]
            ],
            'required' => ['firstName'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_with_default_value()
    {
        $class = new class {
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

    public function test_with_attribute()
    {
        $class = new class {
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

    public function test_nullable_property_with_attribute()
    {
        $class = new class {
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

    public function test_nested_object()
    {
        $schema = (new JsonSchema())->generate(PersonWithAddress::class);

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
                    '$ref' => '#/definitions/Address'
                ]
            ],
            'definitions' => [
                'Address' => [
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
                ]
            ],
            'required' => ['firstName', 'lastName', 'address'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_array_of_objects()
    {
        $schema = (new JsonSchema())->generate(PersonWithTags::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'firstName' => [
                    'type' => 'string',
                ],
                'lastName' => [
                    'type' => 'string',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/definitions/Tag'
                    ]
                ]
            ],
            'definitions' => [
                'Tag' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'description' => 'The name of the tag',
                            'type' => 'string',
                        ]
                    ],
                    'required' => ['name'],
                    'additionalProperties' => false,
                ]
            ],
            'required' => ['firstName', 'lastName', 'tags'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_array_of_objects_with_attribute()
    {
        $class = new class {
            #[ArrayOf(Tag::class)]
            public array $tags;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/definitions/Tag'
                    ]
                ]
            ],
            'definitions' => [
                'Tag' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'description' => 'The name of the tag',
                            'type' => 'string',
                        ]
                    ],
                    'required' => ['name'],
                    'additionalProperties' => false,
                ]
            ],
            'required' => ['tags'],
            'additionalProperties' => false,
        ], $schema);
    }
}
