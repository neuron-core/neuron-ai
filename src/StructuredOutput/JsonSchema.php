<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

class JsonSchema
{
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
        // Reset processed classes for a new generation
        $this->processedClasses = [];

        // Generate the main schema
        return [
            ...$this->generateClassSchema($class),
            'additionalProperties' => false,
        ];
    }

    /**
     * Generate schema for a class
     *
     * @param string $class Class name
     * @return array The schema
     * @throws ReflectionException
     */
    private function generateClassSchema(string $class): array
    {
        $reflection = new ReflectionClass($class);

        // Check for circular reference
        if (\in_array($class, $this->processedClasses)) {
            // For circular references, return a simple object schema to break the cycle
            return ['type' => 'object'];
        }

        $this->processedClasses[] = $class;

        // Handle enum types differently
        if ($reflection->isEnum()) {
            $result = $this->processEnum(new ReflectionEnum($class));
            // Remove the class from the processed list after processing
            \array_pop($this->processedClasses);
            return $result;
        }

        // Create a basic object schema
        $schema = [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];

        $requiredProperties = [];

        // Process all public properties
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        // Process each property
        foreach ($properties as $property) {
            $propertyName = $property->getName();

            $schema['properties'][$propertyName] = $this->processProperty($property);

            $attribute = $this->getPropertyAttribute($property);
            if ($attribute instanceof SchemaProperty && $attribute->required !== null) {
                if ($attribute->required) {
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
        if ($requiredProperties !== []) {
            $schema['required'] = $requiredProperties;
        }

        // Remove the class from the processed list after processing
        \array_pop($this->processedClasses);

        return $schema;
    }

    /**
     * Process a single property to generate its schema
     *
     * @return array Property schema
     * @throws ReflectionException
     */
    private function processProperty(ReflectionProperty $property): array
    {
        $schema = [];

        // Process Property attribute if present
        $attribute = $this->getPropertyAttribute($property);
        if ($attribute instanceof SchemaProperty) {
            if ($attribute->title !== null) {
                $schema['title'] = $attribute->title;
            }

            if ($attribute->description !== null) {
                $schema['description'] = $attribute->description;
            }
        }

        /** @var ?ReflectionNamedType $type */
        $type = $property->getType();
        $typeName = $type?->getName();

        // Handle default values
        if ($property->hasDefaultValue()) {
            $schema['default'] = $property->getDefaultValue();
        }

        // Process different types
        if ($typeName === 'array') {
            $schema['type'] = 'array';

            // Parse PHPDoc for the array item type
            $docComment = $property->getDocComment();
            if ($docComment) {
                // Extract type from both "@var \App\Type[]" and "@var array<\App\Type>"
                \preg_match('/@var\s+(?:([a-zA-Z0-9_\\\\]+)\[\]|array<([a-zA-Z0-9_\\\\]+)>)/', $docComment, $matches);

                if (isset($matches[1]) || isset($matches[2])) {
                    $itemType = empty($matches[1]) ? ((isset($matches[2]) && $matches[2] !== '0') ? $matches[2] : null) : ($matches[1]);

                    // Handle class type for array items
                    if (\class_exists($itemType) || \enum_exists($itemType)) {
                        $schema['items'] = $this->generateClassSchema($itemType);
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
        elseif ($typeName && \enum_exists($typeName)) {
            $enumReflection = new ReflectionEnum($typeName);
            $schema = \array_merge($schema, $this->processEnum($enumReflection));
        }
        // Handle class types
        elseif ($typeName && \class_exists($typeName)) {
            $classSchema = $this->generateClassSchema($typeName);
            $schema = \array_merge($schema, $classSchema);
        }
        // Handle basic types
        elseif ($typeName) {
            $typeSchema = $this->getBasicTypeSchema($typeName);
            $schema = \array_merge($schema, $typeSchema);
        } else {
            // Default to string if no type hint
            $schema['type'] = 'string';
        }

        // Handle nullable types - for basic types only
        if ($type && $type->allowsNull() && isset($schema['type']) && !isset($schema['$ref']) && !isset($schema['allOf'])) {
            if (\is_array($schema['type'])) {
                if (!\in_array('null', $schema['type'])) {
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
     */
    private function processEnum(ReflectionEnum $enum): array
    {
        // Create enum schema
        $schema = [
            'type' => 'string',
            'enum' => [],
        ];

        // Extract enum values
        foreach ($enum->getCases() as $case) {
            if ($enum->isBacked()) {
                /** @var ReflectionEnumBackedCase $case */
                // For backed enums, use the backing value
                $schema['enum'][] = $case->getBackingValue();
            } else {
                // For non-backed enums, use case name
                $schema['enum'][] = $case->getName();
            }
        }

        return $schema;
    }

    /**
     * Get the Property attribute if it exists on a property
     */
    private function getPropertyAttribute(ReflectionProperty $property): ?SchemaProperty
    {
        $attributes = $property->getAttributes(SchemaProperty::class);
        if ($attributes !== []) {
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
                if (\class_exists($type)) {
                    return $this->generateClassSchema($type);
                }
                // Check if it's a class or enum
                if (\enum_exists($type)) {
                    return $this->processEnum(new ReflectionEnum($type));
                }

                // Default to string for unknown types
                return ['type' => 'string'];
        }
    }
}
