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
use function get_debug_type;
use function in_array;
use function is_array;
use function is_string;

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

        $required = static::keyword($schema, 'required', 'array', 'the schema') ?? [];

        foreach (static::keyword($schema, 'properties', 'array', 'the schema') ?? [] as $name => $definition) {
            // PHP turns numeric JSON keys like "1" into integers
            $name = (string) $name;

            if (!is_array($definition)) {
                throw new ToolException("Property '{$name}' must be defined by a schema object, " . get_debug_type($definition) . ' given.');
            }

            $properties[] = static::createProperty($name, $definition, in_array($name, $required, true));
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
            $types = array_values(array_diff($type, ['null']));
            if (count($types) !== 1) {
                throw new ToolException("Property '{$name}' must declare one non-null type.");
            }
            $type = $types[0];
        }

        if (!is_string($type)) {
            throw new ToolException("Property '{$name}' must declare its type as a string, " . get_debug_type($type) . ' given.');
        }

        $propertyType = PropertyType::tryFrom($type)
            ?? throw new ToolException("Unsupported type '{$type}' for property '{$name}'.");
        $description = static::keyword($schema, 'description', 'string', "property '{$name}'");

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
                    ? static::createProperty($name . '_item', static::keyword($schema, 'items', 'array', "property '{$name}'"))
                    : null,
                minItems: static::keyword($schema, 'minItems', 'int', "property '{$name}'"),
                maxItems: static::keyword($schema, 'maxItems', 'int', "property '{$name}'"),
                nullable: $nullable,
            ),
            default => new ToolProperty(
                name: $name,
                type: $propertyType,
                description: $description,
                required: $required,
                enum: static::keyword($schema, 'enum', 'array', "property '{$name}'") ?? [],
                nullable: $nullable,
            ),
        };
    }

    /**
     * Reads an optional schema keyword, refusing a value of the wrong type.
     *
     * @param array<string, mixed> $schema
     * @throws ToolException
     */
    protected static function keyword(array $schema, string $keyword, string $type, string $owner): mixed
    {
        $value = $schema[$keyword] ?? null;

        if ($value !== null && get_debug_type($value) !== $type) {
            throw new ToolException("Keyword '{$keyword}' of {$owner} must be of type {$type}, " . get_debug_type($value) . ' given.');
        }

        return $value;
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
