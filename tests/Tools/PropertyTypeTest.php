<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\PropertyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

class PropertyTypeTest extends TestCase
{
    /**
     * @param string|string[] $schema
     */
    #[DataProvider('schemaTypes')]
    public function test_reads_the_type_from_schema_syntax(array|string $schema, PropertyType $expected): void
    {
        $this->assertSame($expected, PropertyType::fromSchema($schema));
    }

    public static function schemaTypes(): array
    {
        return [
            'plain type' => ['number', PropertyType::NUMBER],
            'nullable type' => [['string', 'null'], PropertyType::STRING],
            'null listed first' => [['null', 'integer'], PropertyType::INTEGER],
            'first supported type wins' => [['boolean', 'array'], PropertyType::BOOLEAN],
            'unknown types are skipped' => [['date', 'object'], PropertyType::OBJECT],
        ];
    }

    #[DataProvider('typelessSchemas')]
    public function test_rejects_a_list_without_a_supported_type(array $schema): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Property type not valid.');

        PropertyType::fromSchema($schema);
    }

    public static function typelessSchemas(): array
    {
        return [
            'empty' => [[]],
            'only null' => [['null']],
            'unknown' => [['date', 'null']],
        ];
    }

    public function test_json_schema_type_names_are_stable(): void
    {
        $this->assertSame(
            ['integer', 'string', 'number', 'boolean', 'array', 'object'],
            array_map(fn (PropertyType $type): string => $type->value, PropertyType::cases())
        );
    }
}
