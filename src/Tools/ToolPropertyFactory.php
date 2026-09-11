<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use ReflectionException;

use function array_diff;
use function array_is_list;
use function array_values;
use function count;
use function in_array;
use function is_array;

/**
 * Converts input schemas to the property types supported by Neuron.
 */
class ToolPropertyFactory
{
    /**
     * @param array<string, mixed> $schema
     * @return ToolPropertyInterface[]
     * @throws ArrayPropertyException
     * @throws ReflectionException
     * @throws ToolException
     */
    public static function fromSchema(array $schema): array
    {
        static::assertSupportedSchema($schema);
        $properties = [];

        foreach ($schema['properties'] ?? [] as $name => $definition) {
            $properties[] = static::createProperty(
                $name,
                $definition,
                in_array($name, $schema['required'] ?? [], true),
            );
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $schema
     * @throws ToolException
     * @throws ArrayPropertyException
     * @throws ReflectionException
     */
    protected static function createProperty(string $name, array $schema, bool $required = false): ToolPropertyInterface
    {
        static::assertSupportedSchema($schema);
        $type = $schema['type'] ?? PropertyType::STRING->value;
        $nullable = is_array($type) && in_array('null', $type, true);
        if (is_array($type)) {
            $types = array_values((array)array_diff($type, ['null']));
            if (count($types) !== 1) {
                throw new ToolException("Property '{$name}' must declare one non-null type.");
            }
            $type = $types[0];
        }

        $propertyType = PropertyType::tryFrom($type)
            ?? throw new ToolException("Unsupported type '{$type}' for property '{$name}'.");
        $description = $schema['description'] ?? null;

        return match ($propertyType) {
            PropertyType::OBJECT => new ObjectProperty(
                name: $name,
                description: $description,
                required: $required,
                properties: static::fromSchema($schema),
                nullable: $nullable,
            ),
            PropertyType::ARRAY => new ArrayProperty(
                name: $name,
                description: $description,
                required: $required,
                items: isset($schema['items'])
                    ? static::createProperty($name . '_item', $schema['items'])
                    : null,
                minItems: $schema['minItems'] ?? null,
                maxItems: $schema['maxItems'] ?? null,
                nullable: $nullable,
            ),
            default => new ToolProperty(
                name: $name,
                type: $propertyType,
                description: $description,
                required: $required,
                enum: $schema['enum'] ?? [],
                nullable: $nullable,
            ),
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @throws ToolException
     */
    protected static function assertSupportedSchema(array $schema): void
    {
        if ($schema !== [] && array_is_list($schema)) {
            throw new ToolException('Tuple schemas cannot be represented by a single array item property.');
        }

        foreach (['$ref', 'oneOf', 'anyOf', 'allOf', 'prefixItems'] as $keyword) {
            if (isset($schema[$keyword])) {
                throw new ToolException("JSON Schema keyword '{$keyword}' cannot be represented by the tool property types.");
            }
        }
    }
}
