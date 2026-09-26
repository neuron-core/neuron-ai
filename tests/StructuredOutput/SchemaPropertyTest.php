<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaPropertiesInterface;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Tests\StructuredOutput\Stub\DynamicPerson;
use NeuronAI\Tests\StructuredOutput\Stub\Tag;
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
}
