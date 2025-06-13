<?php

namespace NeuronAI\Tools;

use NeuronAI\StructuredOutput\JsonSchema;

/**
 * Class PropertyLoader
 *
 * Loads and transforms the properties of a PHP class into a tree of objects
 * implementing ToolPropertyInterface, based on the JSON Schema generated from that class.
 *
 * Supports recursive deserialization of properties, handling primitive types,
 * enums, arrays, and nested objects.
 *
 * Limits the recursion depth to prevent circular references.
 */
class PropertyLoader
{
    private const MAX_RECURSION_DEPTH = 3;

    private const PRIMITIVE_PROPERTY_TYPES = [
        'integer',
        'string',
        'bool',
        'number',
    ];

    private array $properties;
    private array $definitions;
    private array $required = [];

    /**
     * Constructor.
     *
     * Generates the JSON Schema from the given class, then initializes properties,
     * definitions, and the list of required properties.
     *
     * @param string $class Fully qualified class name to analyze.
     *
     * @throws \Exception If the JSON Schema cannot be generated properly.
     */
    public function __construct(string $class)
    {
        $schema = (new JsonSchema())->generate($class);

        $this->properties = $schema['properties'] ?? [];
        $this->definitions = $schema['definitions'] ?? [];

        // Identify required properties
        foreach ($schema['required'] as $r) {
            if (!in_array($r, $this->required)) {
                $this->required[] = $r;
            }
        }
    }

    /**
     * Loads and returns the list of properties as ToolPropertyInterface objects,
     * recursively resolving nested properties up to the maximum depth.
     *
     * @return ToolPropertyInterface[]
     *
     * @throws \Exception If maximum recursion depth is exceeded.
     */
    public function load(): array
    {
        return $this->loadPropertiesRecursive($this->properties, $this->required, self::MAX_RECURSION_DEPTH);
    }

    /**
     * Recursively loads properties and their sub-properties.
     *
     * @param array $properties Properties to load.
     * @param array $required Required properties at this level.
     * @param int $depth Remaining recursion depth allowed.
     *
     * @return ToolPropertyInterface[]
     *
     * @throws \Exception If maximum recursion depth is exceeded.
     */
    private function loadPropertiesRecursive(array $properties, array $required, int $depth): array
    {
        if ($depth === 0) {
            throw new \Exception("Maximum recursion depth exceeded while resolving properties. This may be caused by a circular reference or overly complex nesting. Consider breaking down the structure.");
        }

        $out = [];

        foreach ($properties as $propertyName => $propertyData) {
            $out[] = $this->loadPropertyRecursive($propertyName, $propertyData, $required, $depth);
        }

        return $out;
    }

    /**
     * Loads a single property, handling primitive types, enums,
     * arrays, and objects recursively.
     *
     * @param string $name Property name.
     * @param array $data JSON Schema description of the property.
     * @param array $required List of required properties.
     * @param int $depth Remaining recursion depth allowed.
     *
     * @return ToolPropertyInterface Property instance.
     *
     * @throws \Exception On exceeding recursion depth or missing definition.
     */
    private function loadPropertyRecursive(string $name, array $data, array $required, int $depth): ToolPropertyInterface
    {
        $propertyType = $data['type'] ?? null;
        $propertyDescription = $data['description'] ?? '';
        $isPropertyRequired = in_array($name, $required);

        // Primitive types
        if ($propertyType && in_array($propertyType, self::PRIMITIVE_PROPERTY_TYPES)) {
            return new ToolProperty(
                name: $name,
                type: PropertyType::tryFrom($propertyType),
                description: $propertyDescription,
                required: $isPropertyRequired,
            );
        }

        // Enums via allOf + $ref
        if (isset($data['allOf'][0]['$ref'])) {
            $definition = $this->resolveDefinition($data['allOf'][0]['$ref']);
            return new ToolProperty(
                name: $name,
                type: PropertyType::STRING,
                description: $propertyDescription,
                required: $isPropertyRequired,
                enum: $definition['enum'] ?? [],
            );
        }

        // Array containing primitive types
        if ($propertyType === PropertyType::ARRAY->value && isset($data['items']['type'])) {
            return new ArrayProperty(
                name: $name,
                description: $propertyDescription,
                required: $isPropertyRequired,
                items: ToolProperty::asItem(PropertyType::tryFrom($data['items']['type'])),
            );
        }

        // Array of referenced objects
        if ($propertyType === PropertyType::ARRAY->value && isset($data['items']['$ref'])) {
            $definition = $this->resolveDefinition($data['items']['$ref']);

            return new ArrayProperty(
                name: $name,
                description: $propertyDescription,
                required: $isPropertyRequired,
                items: new ObjectProperty(
                    name: '',
                    description: '',
                    properties: $this->loadPropertiesRecursive($definition['properties'], $definition['required'], $depth - 1),
                    asArrayItem: true
                ),
            );
        }

        // Referenced objects
        $ref = $data['$ref'] ?? null;
        $definition = $this->resolveDefinition($ref);

        return new ObjectProperty(
            name: $name,
            description: $propertyDescription,
            required: $isPropertyRequired,
            properties: $this->loadPropertiesRecursive($definition['properties'], $definition['required'], $depth - 1),
        );

    }

    /**
     * Resolves a definition from a JSON Schema $ref.
     *
     * @param string $ref JSON pointer reference (e.g., "#/definitions/Ingredient").
     *
     * @return array The corresponding definition.
     *
     * @throws \InvalidArgumentException If the reference is null.
     * @throws \Exception If the definition key does not exist.
     */
    private function resolveDefinition(string $ref): array
    {
        if (!$ref) {
            throw new \InvalidArgumentException("Cannot resolve a definition from a null reference. A non-null JSON Schema \$ref is required.");
        }

        $chunks = explode('/', $ref);
        $key = end($chunks);

        if (!isset($this->definitions[$key])) {
            $availableKeys = implode(', ', array_keys($this->definitions));
            throw new \Exception("Definition for reference '$ref' could not be found. Tried key '$key'. Available definition keys: [$availableKeys].");
        }

        return $this->definitions[$key];
    }
}
