<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\SchemaPropertiesInterface;
use NeuronAI\Tests\StructuredOutput\Stub\Address;
use NeuronAI\Tests\StructuredOutput\Stub\Department;
use NeuronAI\Tests\StructuredOutput\Stub\DummyEnum;
use NeuronAI\Tests\StructuredOutput\Stub\DynamicPerson;
use NeuronAI\Tests\StructuredOutput\Stub\Employee;
use NeuronAI\Tests\StructuredOutput\Stub\EmailMode;
use NeuronAI\Tests\StructuredOutput\Stub\FtpMode;
use NeuronAI\Tests\StructuredOutput\Stub\ImageBlock;
use NeuronAI\Tests\StructuredOutput\Stub\Person;
use NeuronAI\Tests\StructuredOutput\Stub\StringEnum;
use NeuronAI\Tests\StructuredOutput\Stub\TextBlock;
use NeuronAI\Tests\StructuredOutput\Stub\TreeNode;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use PHPUnit\Framework\TestCase;
use ReflectionException;

use function array_keys;

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
                ],
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
                    'default' => null,
                ],
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
                ],
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
                ],
            ],
            'required' => ['firstName'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_required_false_attribute_makes_non_nullable_property_optional(): void
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
                ],
            ],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_schema_properties_interface(): void
    {
        $schema = (new JsonSchema())->generate(DynamicPerson::class);

        // Runtime definition from schemaProperties()
        $this->assertEquals('Runtime description', $schema['properties']['firstName']['description']);
        // Fallback to the attribute when no runtime entry exists
        $this->assertEquals('Attribute description', $schema['properties']['lastName']['description']);
        // Runtime definition wins over the attribute
        $this->assertEquals('Runtime wins', $schema['properties']['nickName']['description']);
        // anyOf provided at runtime resolves the array items schema
        $this->assertEquals('array', $schema['properties']['tags']['type']);
        $this->assertEquals('object', $schema['properties']['tags']['items']['type']);
        $this->assertArrayHasKey('name', $schema['properties']['tags']['items']['properties']);
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
                        ],
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
                                        ],
                                    ],
                                    'additionalProperties' => false,
                                    'required' => ['value'],
                                ],
                            ],
                        ],
                        'required' => ['name'],
                        'additionalProperties' => false,
                    ],
                ],
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
                                'description' => 'The name of the user',
                            ],
                        ],
                        'required' => ['name'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'The list of users',
                ],
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

        $this->assertSame([
            'type' => 'array',
            'items' => [
                'anyOf' => [
                    [
                        'type' => 'object',
                        'properties' => [
                            '__classname__' => [
                                'type' => 'string',
                                'enum' => ['ftpmode'],
                                'description' => 'This property is mandatory and can only be filled with "ftpmode". It is used as a discriminator for class type resolution.',
                            ],
                            'mode' => ['default' => 'ftp', 'type' => 'string'],
                            'account' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                        'required' => ['__classname__', 'account'],
                    ],
                    [
                        'type' => 'object',
                        'properties' => [
                            '__classname__' => [
                                'type' => 'string',
                                'enum' => ['emailmode'],
                                'description' => 'This property is mandatory and can only be filled with "emailmode". It is used as a discriminator for class type resolution.',
                            ],
                            'mode' => ['default' => 'email', 'type' => 'string'],
                            'mailingList' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                        'required' => ['__classname__', 'mailingList'],
                    ],
                ],
            ],
        ], $schema['properties']['modes']);
        $this->assertSame(['modes'], $schema['required']);
    }

    public function test_custom_discriminator_name_is_injected_into_anyof_items(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [ImageBlock::class, TextBlock::class])]
            public array $blocks;
        };

        $schema = (new JsonSchema('kind'))->generate($class::class);

        $items = $schema['properties']['blocks']['items']['anyOf'];
        $this->assertSame(['kind', 'type', 'url'], array_keys($items[0]['properties']));
        $this->assertSame(['imageblock'], $items[0]['properties']['kind']['enum']);
        $this->assertSame(['kind', 'content'], $items[1]['required']);
        $this->assertSame(['textblock'], $items[1]['properties']['kind']['enum']);
        $this->assertArrayNotHasKey('__classname__', $items[1]['properties']);
    }

    public function test_discriminator_is_only_injected_into_object_items(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [FtpMode::class, StringEnum::class])]
            public array $values;
        };

        $items = (new JsonSchema())->generate($class::class)['properties']['values']['items']['anyOf'];

        $this->assertSame(['__classname__', 'mode', 'account'], array_keys($items[0]['properties']));
        $this->assertSame(['type' => 'string', 'enum' => ['one', 'two', 'three']], $items[1]);
    }

    public function test_discriminator_is_not_injected_for_single_type_arrays(): void
    {
        $schema = (new JsonSchema())->generate(Person::class);

        $this->assertArrayNotHasKey('anyOf', $schema['properties']['tags']['items']);
        $this->assertArrayNotHasKey('__classname__', $schema['properties']['tags']['items']['properties']);
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
                ],
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
                ],
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
                ],
            ],
            'required' => ['tags'],
            'additionalProperties' => false,
        ], $schema);
    }

    public function test_scalar_types_mapping(): void
    {
        $class = new class () {
            public string $text;
            public int $count;
            public float $ratio;
            public bool $active;
            public ?int $optionalCount;
            public ?bool $optionalFlag = true;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame([
            'text' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'ratio' => ['type' => 'number'],
            'active' => ['type' => 'boolean'],
            'optionalCount' => ['type' => ['integer', 'null']],
            'optionalFlag' => ['default' => true, 'type' => ['boolean', 'null']],
        ], $schema['properties']);
        $this->assertSame(['text', 'count', 'ratio', 'active'], $schema['required']);
    }

    public function test_non_nullable_property_with_default_is_not_required(): void
    {
        $class = new class () {
            public int $page = 1;
            public array $filters = [];
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertArrayNotHasKey('required', $schema);
        $this->assertSame(['default' => 1, 'type' => 'integer'], $schema['properties']['page']);
        $this->assertSame(['default' => [], 'type' => 'array', 'items' => ['type' => 'string']], $schema['properties']['filters']);
    }

    public function test_required_true_attribute_forces_nullable_property_into_required(): void
    {
        $class = new class () {
            #[SchemaProperty(required: true)]
            public ?string $nickname = null;

            #[SchemaProperty(description: 'Attribute without required flag')]
            public ?string $bio;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame(['nickname'], $schema['required']);
    }

    public function test_only_public_properties_are_exposed(): void
    {
        $class = new class () {
            public string $visible;
            protected string $internal;
            private string $secret = '';

            public function secret(): string
            {
                return $this->secret;
            }
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame(['visible'], array_keys($schema['properties']));
        $this->assertSame(['visible'], $schema['required']);
    }

    public function test_class_without_public_properties(): void
    {
        $class = new class () {
        };

        $this->assertSame(
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            (new JsonSchema())->generate($class::class)
        );
    }

    public function test_string_backed_enum_property(): void
    {
        $class = new class () {
            public StringEnum $number;
            public ?StringEnum $optional;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame(['type' => 'string', 'enum' => ['one', 'two', 'three']], $schema['properties']['number']);
        $this->assertSame(['type' => ['string', 'null'], 'enum' => ['one', 'two', 'three']], $schema['properties']['optional']);
        $this->assertSame(['number'], $schema['required']);
    }

    public function test_pure_enum_property_uses_case_names(): void
    {
        $class = new class () {
            #[SchemaProperty(description: 'Pick one')]
            public DummyEnum $choice;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame(['description' => 'Pick one', 'type' => 'string', 'enum' => ['A', 'B']], $schema['properties']['choice']);
    }

    public function test_enum_as_root_class(): void
    {
        $this->assertSame(
            ['type' => 'string', 'enum' => ['one', 'two', 'three'], 'additionalProperties' => false],
            (new JsonSchema())->generate(StringEnum::class)
        );
    }

    public function test_nullable_object_property_keeps_its_schema(): void
    {
        $class = new class () {
            #[SchemaProperty(description: 'Where the user lives')]
            public ?User $owner = null;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame([
            'description' => 'Where the user lives',
            'default' => null,
            'type' => ['object', 'null'],
            'properties' => ['name' => ['description' => 'The name of the user', 'type' => 'string']],
            'additionalProperties' => false,
            'required' => ['name'],
        ], $schema['properties']['owner']);
    }

    public function test_self_referencing_class_does_not_recurse_forever(): void
    {
        $schema = (new JsonSchema())->generate(TreeNode::class);

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'parent' => ['default' => null, 'type' => ['object', 'null']],
                'children' => ['default' => [], 'type' => 'array', 'items' => ['type' => 'object']],
            ],
            'additionalProperties' => false,
            'required' => ['name'],
        ], $schema);
    }

    public function test_mutually_referencing_classes_are_expanded_once_per_branch(): void
    {
        $schema = (new JsonSchema())->generate(Employee::class);

        $department = $schema['properties']['department'];
        $this->assertSame(['title', 'manager'], array_keys($department['properties']));
        $this->assertSame(['default' => null, 'type' => ['object', 'null']], $department['properties']['manager']);
        $this->assertSame(['name', 'department'], $schema['required']);
    }

    public function test_sibling_properties_of_the_same_class_are_all_expanded(): void
    {
        $class = new class () {
            public Address $billing;
            public Address $shipping;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame($schema['properties']['billing'], $schema['properties']['shipping']);
        $this->assertSame(['street', 'city', 'zip'], array_keys($schema['properties']['shipping']['properties']));
    }

    public function test_generator_instance_can_be_reused(): void
    {
        $generator = new JsonSchema();

        $first = $generator->generate(Employee::class);
        $generator->generate(TreeNode::class);

        $this->assertSame($first, $generator->generate(Employee::class));
        $this->assertSame((new JsonSchema())->generate(Department::class), $generator->generate(Department::class));
    }

    public function test_failed_generation_does_not_poison_the_generator_instance(): void
    {
        $invalid = new class () {
            public Employee $employee;

            #[SchemaProperty(anyOf: ['NeuronAI\Tests\StructuredOutput\Stub\DoesNotExist'])]
            public array $values;
        };
        $generator = new JsonSchema();

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $generator->generate($invalid::class);
                $this->fail("Attempt {$attempt}: generation with an unknown anyOf class must fail");
            } catch (ReflectionException) {
            }
        }

        $this->assertSame((new JsonSchema())->generate(Employee::class), $generator->generate(Employee::class));
    }

    public function test_numeric_constraints_apply_to_floats(): void
    {
        $class = new class () {
            #[SchemaProperty(min: 0, max: 1)]
            public ?float $score;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame(['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1], $schema['properties']['score']);
    }

    public function test_constraints_are_only_applied_to_matching_types(): void
    {
        $class = new class () {
            #[SchemaProperty(min: 1, max: 5)]
            public string $code;

            #[SchemaProperty(minLength: 1, maxLength: 5)]
            public int $amount;

            #[SchemaProperty(minLength: 1, maxLength: 5)]
            public array $items;

            #[SchemaProperty(min: 1, max: 5, minLength: 1, maxLength: 5)]
            public bool $flag;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame(['type' => 'string'], $schema['properties']['code']);
        $this->assertSame(['type' => 'integer'], $schema['properties']['amount']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $schema['properties']['items']);
        $this->assertSame(['type' => 'boolean'], $schema['properties']['flag']);
    }

    public function test_zero_constraints_are_kept(): void
    {
        $class = new class () {
            #[SchemaProperty(min: 0, max: 0)]
            public array $none;

            #[SchemaProperty(minLength: 0)]
            public string $text;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame(0, $schema['properties']['none']['minItems']);
        $this->assertSame(0, $schema['properties']['none']['maxItems']);
        $this->assertSame(0, $schema['properties']['text']['minLength']);
    }

    public function test_empty_anyof_falls_back_to_string_items(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [])]
            public array $values;
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $schema['properties']['values']);
    }

    public function test_anyof_with_unknown_class_is_rejected(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: ['NeuronAI\Tests\StructuredOutput\Stub\DoesNotExist'])]
            public array $values;
        };

        $this->expectException(ReflectionException::class);
        $this->expectExceptionMessage('Class "NeuronAI\\Tests\\StructuredOutput\\Stub\\DoesNotExist" does not exist');

        (new JsonSchema())->generate($class::class);
    }

    public function test_unknown_root_class_is_rejected(): void
    {
        $this->expectException(ReflectionException::class);
        $this->expectExceptionMessage('Class "NeuronAI\\Tests\\StructuredOutput\\Stub\\DoesNotExist" does not exist');

        (new JsonSchema())->generate('NeuronAI\Tests\StructuredOutput\Stub\DoesNotExist');
    }

    public function test_schema_properties_interface_controls_required_flag(): void
    {
        $class = new class () implements SchemaPropertiesInterface {
            #[SchemaProperty(required: true)]
            public ?string $overridden = null;

            public string $optionalAtRuntime;

            public string $inferred;

            public static function schemaProperties(): array
            {
                return [
                    'overridden' => new SchemaProperty(description: 'Runtime entry replaces the whole attribute'),
                    'optionalAtRuntime' => new SchemaProperty(required: false, maxLength: 3),
                    'unknownProperty' => new SchemaProperty(required: true),
                ];
            }
        };

        $schema = (new JsonSchema())->generate($class::class);

        $this->assertSame(['inferred'], $schema['required']);
        $this->assertSame(['overridden', 'optionalAtRuntime', 'inferred'], array_keys($schema['properties']));
        $this->assertSame(['type' => 'string', 'maxLength' => 3], $schema['properties']['optionalAtRuntime']);
    }
}
