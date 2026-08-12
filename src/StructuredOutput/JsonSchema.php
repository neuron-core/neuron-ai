<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

use NeuronAI\StaticConstructor;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

use function array_merge;
use function array_pop;
use function array_unique;
use function basename;
use function class_exists;
use function count;
use function enum_exists;
use function in_array;
use function str_replace;
use function strtolower;
use function is_array;

/**
 * @method static static make(string $discriminator = '__classname__')
 */
class JsonSchema
{
    use StaticConstructor;

    /**
     * Classes currently being processed, to break circular references.
     */
    protected array $processedClasses = [];

    public function __construct(protected string $discriminator = '__classname__')
    {
    }

    /**
     * Generate the JSON schema for a fully qualified class name.
     *
     * @throws ReflectionException
     */
    public function generate(string $class): array
    {
        $this->processedClasses = [];

        return [
            ...$this->generateClassSchema($class),
            'additionalProperties' => false,
        ];
    }

    /**
     * @throws ReflectionException
     */
    protected function generateClassSchema(string $class): array
    {
        $reflection = new ReflectionClass($class);

        // Circular reference: return a bare object schema to break the cycle
        if (in_array($class, $this->processedClasses)) {
            return ['type' => 'object'];
        }

        $this->processedClasses[] = $class;

        if ($reflection->isEnum()) {
            $result = $this->processEnum(new ReflectionEnum($class));
            array_pop($this->processedClasses);
            return $result;
        }

        $schema = [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];

        $requiredProperties = [];

        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            $schema['properties'][$propertyName] = $this->processProperty($property);

            $attribute = $this->getPropertyAttribute($property);
            if ($attribute instanceof SchemaProperty && $attribute->required !== null) {
                if ($attribute->required) {
                    $requiredProperties[] = $propertyName;
                }
            } else {
                // No attribute: non-nullable properties without a default are required
                $type = $property->getType();

                $isNullable = $type ? $type->allowsNull() : true;

                if (!$isNullable && !$property->hasDefaultValue()) {
                    $requiredProperties[] = $propertyName;
                }
            }
        }

        if ($requiredProperties !== []) {
            $schema['required'] = $requiredProperties;
        }

        array_pop($this->processedClasses);

        return $schema;
    }

    /**
     * @throws ReflectionException
     */
    protected function processProperty(ReflectionProperty $property): array
    {
        $schema = [];

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

        if ($property->hasDefaultValue()) {
            $schema['default'] = $property->getDefaultValue();
        }

        if ($typeName === 'array') {
            $schema['type'] = 'array';

            if ($attribute instanceof SchemaProperty && $attribute->anyOf !== null && $attribute->anyOf !== []) {
                if (count($attribute->anyOf) === 1) {
                    $schema['items'] = $this->generateClassSchema($attribute->anyOf[0]);
                } else {
                    $schema['items'] = $this->generateAnyOfSchema($attribute->anyOf);
                }
            } else {
                $schema['items'] = ['type' => 'string'];
            }

            if ($attribute instanceof SchemaProperty) {
                if ($attribute->min !== null) {
                    $schema['minItems'] = $attribute->min;
                }
                if ($attribute->max !== null) {
                    $schema['maxItems'] = $attribute->max;
                }
            }
        } elseif ($typeName && enum_exists($typeName)) {
            $enumReflection = new ReflectionEnum($typeName);
            $schema = array_merge($schema, $this->processEnum($enumReflection));
        } elseif ($typeName && class_exists($typeName)) {
            $classSchema = $this->generateClassSchema($typeName);
            $schema = array_merge($schema, $classSchema);
        } elseif ($typeName) {
            $typeSchema = $this->getBasicTypeSchema($typeName);
            $schema = array_merge($schema, $typeSchema);

            if ($attribute instanceof SchemaProperty) {
                if (in_array($typeName, ['int', 'integer', 'float', 'double'])) {
                    if ($attribute->min !== null) {
                        $schema['minimum'] = $attribute->min;
                    }
                    if ($attribute->max !== null) {
                        $schema['maximum'] = $attribute->max;
                    }
                }

                if ($typeName === 'string') {
                    if ($attribute->minLength !== null) {
                        $schema['minLength'] = $attribute->minLength;
                    }
                    if ($attribute->maxLength !== null) {
                        $schema['maxLength'] = $attribute->maxLength;
                    }
                }
            }
        } else {
            $schema['type'] = 'string';
        }

        // Nullability applies only to inline basic types, not $ref/allOf schemas
        if ($type && isset($schema['type']) && !isset($schema['$ref']) && !isset($schema['allOf']) && $type->allowsNull()) {
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

    protected function processEnum(ReflectionEnum $enum): array
    {
        $schema = [
            'type' => 'string',
            'enum' => [],
        ];

        foreach ($enum->getCases() as $case) {
            if ($enum->isBacked()) {
                /** @var ReflectionEnumBackedCase $case */
                $schema['enum'][] = $case->getBackingValue();
            } else {
                $schema['enum'][] = $case->getName();
            }
        }

        return $schema;
    }

    /**
     * The SchemaPropertiesInterface runtime map wins over the attribute.
     */
    protected function getPropertyAttribute(ReflectionProperty $property): ?SchemaProperty
    {
        return SchemaProperty::resolve($property);
    }

    /**
     * @throws ReflectionException
     */
    protected function getBasicTypeSchema(string $type): array
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
                if (class_exists($type)) {
                    return $this->generateClassSchema($type);
                }
                if (enum_exists($type)) {
                    return $this->processEnum(new ReflectionEnum($type));
                }

                return ['type' => 'string'];
        }
    }

    /**
     * @param string[] $types Class/enum type strings
     * @throws ReflectionException
     */
    protected function generateAnyOfSchema(array $types): array
    {
        $schemas = [];

        foreach ($types as $type) {
            $schema = null;

            if (class_exists($type)) {
                $schema = $this->generateClassSchema($type);
            } elseif (enum_exists($type)) {
                $schema = $this->processEnum(new ReflectionEnum($type));
            }

            if ($schema !== null) {
                $shortName = strtolower(basename(str_replace('\\', '/', $type)));
                $schema = $this->injectDiscriminator($schema, $shortName);
                $schemas[] = $schema;
            }
        }

        return ['anyOf' => $schemas];
    }

    /**
     * Inject a required discriminator field (lowercase class name) into object
     * schemas so the Deserializer can resolve the concrete anyOf type.
     */
    protected function injectDiscriminator(array $schema, string $discriminatorValue): array
    {
        if (isset($schema['type']) && $schema['type'] === 'object') {
            $schema['properties'] = [
                $this->discriminator => [
                    'type' => 'string',
                    'enum' => [$discriminatorValue],
                    'description' => 'This property is mandatory and can only be filled with "'.$discriminatorValue.'". It is used as a discriminator for class type resolution.',
                ],
                ...($schema['properties'] ?? []),
            ];

            $schema['required'] = array_unique([
                $this->discriminator,
                ...($schema['required'] ?? []),
            ]);
        }

        return $schema;
    }
}
