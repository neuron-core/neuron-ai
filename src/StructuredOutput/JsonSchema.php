<?php

namespace NeuronAI\StructuredOutput;

use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionProperty;

class JsonSchema
{
    /**
     * Store processed class definitions to avoid duplication
     */
    private array $definitions = [];

    /**
     * Track classes being processed to prevent infinite recursion
     */
    private array $processedClasses = [];

    /**
     * Generate JSON schema from a PHP class
     *
     * @param string $class Fully qualified class name
     * @return array JSON schema definition
     * @throws ReflectionException
     */
    public function generate(string $class): array
    {
        // Reset definitions for a new generation
        $this->definitions = [];
        $this->processedClasses = [];

        // Generate the main schema
        $schema = [
            ...$this->generateClassSchema($class),
            'additionalProperties' => false,
        ];

        // Add definitions if any exist
        if (!empty($this->definitions)) {
            $schema['definitions'] = \array_map(function (array $definition) {
                return [...$definition, 'additionalProperties' => false];
            }, $this->definitions);
        }

        return $schema;
    }

    /**
     * Generate schema for a class
     *
     * @param string $class Class name
     * @param bool $isRoot Whether this is the root schema
     * @return array The schema or reference
     * @throws ReflectionException
     */
    private function generateClassSchema(string $class, bool $isRoot = true): array
    {
        $reflection = new ReflectionClass($class);
        $className = $reflection->getShortName();

        // If we've already processed this class, and it's not the root, return a reference
        if (!$isRoot && in_array($class, $this->processedClasses)) {
            return [
                '$ref' => '#/definitions/' . $className,
            ];
        }

        $this->processedClasses[] = $class;

        // Handle enum types differently
        if ($reflection->isEnum()) {
            return $this->processEnum($reflection, $isRoot);
        }

        // Create a basic object schema
        $schema = [
            'type' => 'object',
            'properties' => [],
        ];

        $requiredProperties = [];

        // Process all public properties
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        // Process each property
        foreach ($properties as $property) {
            $propertyName = $property->getName();

            $schema['properties'][$propertyName] = $this->processProperty($property);

            $attribute = $this->getPropertyAttribute($property);
            if (!empty($attribute) && $attribute->required !== null) {
                if ($attribute->required === true) {
                    $requiredProperties[] = $propertyName;
                }
            } else {
                // If the attribute is not available,
                // use the default logic for required properties
                $type = $property->getType();

                $isNullable = $type ? $type->allowsNull() : true;

                if (!$isNullable && !$property->hasDefaultValue()) {
                    $requiredProperties[] = $propertyName;
                }
            }
        }

        // Add required properties
        if (!empty($requiredProperties)) {
            $schema['required'] = $requiredProperties;
        }

        // If not root, add to definitions and return reference
        if (!$isRoot) {
            $this->definitions[$className] = $schema;
            return [
                '$ref' => '#/definitions/' . $className,
            ];
        }

        return $schema;
    }

    /**
     * Process a single property to generate its schema
     *
     * @param ReflectionProperty $property
     * @return array Property schema
     * @throws ReflectionException
     */
    private function processProperty(ReflectionProperty $property): array
    {
        $schema = [];

        // Process Property attribute if present
        $attribute = $this->getPropertyAttribute($property);
        if (!empty($attribute)) {
            if ($attribute->title !== null) {
                $schema['title'] = $attribute->title;
            }

            if ($attribute->description !== null) {
                $schema['description'] = $attribute->description;
            }
        }

        $type = $property->getType();
        $typeName = $type?->getName();

        // Handle default values
        if ($property->hasDefaultValue()) {
            $schema['default'] = $property->getDefaultValue();
        }

        // Process different types
        if ($typeName === 'array') {
            $schema['type'] = 'array';

            // Check for ArrayOf attribute
            $attributes = $property->getAttributes(ArrayOf::class);
            // Parse PHPDoc for the array item type
            $docComment = $property->getDocComment();
            if ($attributes) {
                $itemClass = $attributes[0]->getArguments()[0];
                $schema['items'] = $this->generateClassSchema($itemClass, false);
            } elseif ($docComment) {
                // Extract type from @var array<Type>
                preg_match('/@var\s+array<([^>]*)>/', $docComment, $matches);

                if (isset($matches[1])) {
                    $itemType = trim($matches[1]);

                    // Handle class type for array items
                    if (class_exists($itemType) || enum_exists($itemType)) {
                        $schema['items'] = $this->generateClassSchema($itemType, false);
                    } else {
                        // Basic type
                        $schema['items'] = $this->getBasicTypeSchema($itemType);
                    }
                } else {
                    // Default to string if no specific type found
                    $schema['items'] = ['type' => 'string'];
                }
            } else {
                // Default to string if no doc comment
                $schema['items'] = ['type' => 'string'];
            }
        }
        // Handle enum types
        elseif ($typeName && enum_exists($typeName)) {
            $enumReflection = new ReflectionEnum($typeName);
            $this->processEnum($enumReflection); // Ensure enum is in definitions

            $schema['allOf'] = [
                [
                    '$ref' => '#/definitions/' . $enumReflection->getShortName(),
                ],
            ];
        }
        // Handle class types
        elseif ($typeName && class_exists($typeName)) {
            $classSchema = $this->generateClassSchema($typeName, false);
            $schema = array_merge($schema, $classSchema);
        }
        // Handle basic types
        elseif ($typeName) {
            $typeSchema = $this->getBasicTypeSchema($typeName);
            $schema = array_merge($schema, $typeSchema);
        } else {
            // Default to string if no type hint
            $schema['type'] = 'string';
        }

        // Handle nullable types - for basic types only
        if ($type && $type->allowsNull() && isset($schema['type']) && !isset($schema['$ref']) && !isset($schema['allOf'])) {
            if (is_array($schema['type'])) {
                if (!in_array('null', $schema['type'])) {
                    $schema['type'][] = 'null';
                }
            } else {
                $schema['type'] = [$schema['type'], 'null'];
            }
        }

        return $schema;
    }

    /**
     * Process an enum to generate its schema
     *
     * @param ReflectionEnum $enum
     * @param bool $isRoot
     * @return array
     */
    private function processEnum(ReflectionEnum $enum, bool $isRoot = false): array
    {
        $enumName = $enum->getShortName();

        // Return reference if already processed
        if (!$isRoot && isset($this->definitions[$enumName])) {
            return [
                '$ref' => '#/definitions/' . $enumName,
            ];
        }

        // Create enum schema
        $schema = [
            'type' => 'string',
            'enum' => [],
        ];

        // Extract enum values
        foreach ($enum->getCases() as $case) {
            if ($enum->isBacked()) {
                // For backed enums, use the backing value
                $schema['enum'][] = $case->getBackingValue();
            } else {
                // For non-backed enums, use case name
                $schema['enum'][] = $case->getName();
            }
        }

        // Add to definitions if not root schema
        if (!$isRoot) {
            $this->definitions[$enumName] = $schema;
            return [
                '$ref' => '#/definitions/' . $enumName,
            ];
        }

        return $schema;
    }

    /**
     * Get the Property attribute if it exists on a property
     *
     * @param ReflectionProperty $property
     * @return SchemaProperty|null
     */
    private function getPropertyAttribute(ReflectionProperty $property): ?SchemaProperty
    {
        $attributes = $property->getAttributes(SchemaProperty::class);
        if (!empty($attributes)) {
            return $attributes[0]->newInstance();
        }
        return null;
    }

    /**
     * Get schema for a basic PHP type
     *
     * @param string $type PHP type name
     * @return array Schema for the type
     * @throws ReflectionException
     */
    private function getBasicTypeSchema(string $type): array
    {
        switch ($type) {
            case 'string':
                return ['type' => 'string'];

            case 'int':
            case 'integer':
                return ['type' => 'integer'];

            case 'float':
            case 'double':
                return ['type' => 'number'];

            case 'bool':
            case 'boolean':
                return ['type' => 'boolean'];

            case 'array':
                return [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ];

            default:
                // Check if it's a class or enum
                if (class_exists($type)) {
                    return $this->generateClassSchema($type, false);
                } elseif (enum_exists($type)) {
                    return $this->processEnum(new ReflectionEnum($type));
                }

                // Default to string for unknown types
                return ['type' => 'string'];
        }
    }
}
