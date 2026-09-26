<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\JsonSchema;
use PHPUnit\Framework\TestCase;

class UnionTypesTest extends TestCase
{
    public function test_schema_generation_supports_union_types(): void
    {
        $class = new class () {
            public int|string $identifier;
        };

        $schema = JsonSchema::make()->generate($class::class);

        $this->assertEqualsCanonicalizing(
            [['type' => 'integer'], ['type' => 'string']],
            $schema['properties']['identifier']['anyOf']
        );
        $this->assertSame(['identifier'], $schema['required']);
    }

    public function test_union_value_keeps_the_matching_member_type(): void
    {
        $class = new class () {
            public int|string $identifier;
        };

        $this->assertSame(5, Deserializer::make()->fromJson('{"identifier": 5}', $class::class)->identifier);
        $this->assertSame('abc', Deserializer::make()->fromJson('{"identifier": "abc"}', $class::class)->identifier);
    }

    public function test_self_typed_property_is_an_object_in_schema(): void
    {
        $schema = JsonSchema::make()->generate(SelfLinkedNode::class);

        $this->assertSame(['object', 'null'], $schema['properties']['next']['type']);
    }

    public function test_self_typed_property_deserializes_to_the_declaring_class(): void
    {
        $node = Deserializer::make()->fromJson('{"value": "a", "next": {"value": "b"}}', SelfLinkedNode::class);

        $this->assertInstanceOf(SelfLinkedNode::class, $node->next);
        $this->assertSame('b', $node->next->value);
        $this->assertNull($node->next->next);
    }
}

class SelfLinkedNode
{
    public string $value;

    public ?self $next = null;
}
