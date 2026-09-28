<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaPropertiesInterface;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Tests\StructuredOutput\Stub\DynamicPerson;
use NeuronAI\Tests\StructuredOutput\Stub\Tag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class SchemaPropertyTest extends TestCase
{
    public function test_resolves_the_attribute_of_a_plain_class(): void
    {
        $class = new class () {
            #[SchemaProperty(title: 'Title', description: 'Desc', required: true, min: 1, max: 2, minLength: 3, maxLength: 4, anyOf: [Tag::class])]
            public array $tags;
        };

        $resolved = SchemaProperty::resolve(new ReflectionProperty($class, 'tags'));

        $this->assertEquals(
            new SchemaProperty('Title', 'Desc', true, 1, 2, 3, 4, [Tag::class]),
            $resolved
        );
    }

    public function test_resolves_null_without_attribute_or_runtime_entry(): void
    {
        $class = new class () {
            public string $name;
        };

        $this->assertNull(SchemaProperty::resolve(new ReflectionProperty($class, 'name')));
    }

    public function test_runtime_entry_replaces_the_attribute_entirely(): void
    {
        $resolved = SchemaProperty::resolve(new ReflectionProperty(DynamicPerson::class, 'nickName'));

        $this->assertEquals(new SchemaProperty(description: 'Runtime wins'), $resolved);
    }

    public function test_attribute_is_used_when_the_runtime_map_has_no_entry(): void
    {
        $resolved = SchemaProperty::resolve(new ReflectionProperty(DynamicPerson::class, 'lastName'));

        $this->assertEquals(new SchemaProperty(description: 'Attribute description'), $resolved);
    }

    public function test_runtime_entry_that_is_not_a_schema_property_is_ignored(): void
    {
        $class = new class () implements SchemaPropertiesInterface {
            #[SchemaProperty(description: 'From attribute')]
            public string $name;

            public string $bare;

            /**
             * @return array<string, mixed>
             */
            public static function schemaProperties(): array
            {
                return ['name' => 'not a schema property', 'bare' => ['description' => 'array config']];
            }
        };

        $this->assertEquals(
            new SchemaProperty(description: 'From attribute'),
            SchemaProperty::resolve(new ReflectionProperty($class, 'name'))
        );
        $this->assertNull(SchemaProperty::resolve(new ReflectionProperty($class, 'bare')));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function requiredRule(): array
    {
        return [
            'a type that cannot hold null' => ['name', true],
            'a nullable type' => ['nickname', false],
            'mixed' => ['anything', false],
            'a declared default' => ['status', false],
            'a promoted parameter without a default' => ['id', true],
            'a promoted default' => ['title', false],
            'required by the attribute despite a default' => ['country', true],
            'optional by the attribute despite no default' => ['age', false],
        ];
    }

    #[DataProvider('requiredRule')]
    public function test_a_property_is_required_when_it_cannot_be_null_and_has_no_default(string $property, bool $required): void
    {
        $class = new class (1) {
            public string $name;

            public ?string $nickname;

            public mixed $anything;

            public string $status = 'draft';

            #[SchemaProperty(required: true)]
            public string $country = 'IT';

            #[SchemaProperty(required: false)]
            public int $age;

            public function __construct(public int $id, public string $title = 'untitled')
            {
            }
        };

        $this->assertSame($required, SchemaProperty::isRequired(new ReflectionProperty($class, $property)));
    }

    public function test_a_runtime_entry_decides_whether_the_property_is_required(): void
    {
        $class = new class () implements SchemaPropertiesInterface {
            #[SchemaProperty(required: true)]
            public string $name;

            public static function schemaProperties(): array
            {
                return ['name' => new SchemaProperty(required: false)];
            }
        };

        $this->assertFalse(SchemaProperty::isRequired(new ReflectionProperty($class, 'name')));
    }
}
