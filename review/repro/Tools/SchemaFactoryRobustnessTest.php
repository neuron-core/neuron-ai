<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolPropertyFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SchemaFactoryRobustnessTest extends TestCase
{
    public function test_numeric_property_names_are_supported(): void
    {
        $tool = new FrontendTool('pick', 'Pick an option.', [
            'type' => 'object',
            'properties' => ['1' => ['type' => 'string']],
            'required' => ['1'],
        ]);

        $properties = $tool->getProperties();
        $this->assertSame('1', $properties[0]->getName());
        $this->assertTrue($properties[0]->isRequired());
    }

    /** @param array<string, mixed> $schema */
    #[DataProvider('malformedSchemas')]
    public function test_malformed_schemas_are_a_tool_exception(array $schema): void
    {
        $this->expectException(ToolException::class);

        ToolPropertyFactory::fromSchema($schema);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedSchemas(): array
    {
        return [
            'numeric type' => [['properties' => ['a' => ['type' => 5]]]],
            'boolean required' => [['properties' => ['a' => ['type' => 'string']], 'required' => true]],
            'boolean items' => [['properties' => ['a' => ['type' => 'array', 'items' => true]]]],
            'string enum' => [['properties' => ['a' => ['type' => 'string', 'enum' => 'x']]]],
            'string definition' => [['properties' => ['a' => 'string']]],
            'string properties' => [['properties' => 'a']],
            'string bound' => [['properties' => ['a' => ['type' => 'array', 'minItems' => '1']]]],
            'numeric description' => [['properties' => ['a' => ['type' => 'string', 'description' => 5]]]],
        ];
    }

    public function test_unknown_property_type_is_a_tool_exception(): void
    {
        $this->expectException(ToolException::class);

        PropertyType::fromSchema('unknown');
    }
}
