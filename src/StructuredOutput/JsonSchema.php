<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

use BackedEnum;
use NeuronAI\StaticConstructor;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use stdClass;
use UnitEnum;

use function array_map;
use function array_merge;
use function array_pop;
use function array_values;
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
            if ($property->isStatic()) {
                continue;
            }

            $propertyName = $property->getName();

            $schema['properties'][$propertyName] = $this->processProperty($reflection, $property);

            if (SchemaProperty::isRequired($reflection, $property)) {
                $requiredProperties[] = $propertyName;
            }
        }

        // An empty array would be encoded as a JSON list, not an object
        if ($schema['properties'] === []) {
            $schema['properties'] = new stdClass();
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
    protected function processProperty(ReflectionClass $class, ReflectionProperty $property): array
    {
        $schema = [];

        $attribute = $this->getPropertyAttribute($class, $property);
        if ($attribute instanceof SchemaProperty) {
            if ($attribute->title !== null) {
                $schema['title'] = $attribute->title;
            }

            if ($attribute->description !== null) {
                $schema['description'] = $attribute->description;
            }
        }

        $type = $property->getType();

        if ($property->hasDefaultValue()) {
            $schema['default'] = $this->schemaValue($property->getDefaultValue());
        } elseif ($property->isPromoted()) {
            // A promoted property's default lives on its constructor parameter
            $parameter = new ReflectionParameter([$property->class, '__construct'], $property->name);

            if ($parameter->isDefaultValueAvailable()) {
                $schema['default'] = $this->schemaValue($parameter->getDefaultValue());
            }
        }

        if ($type instanceof ReflectionUnionType) {
            $schema['anyOf'] = array_map(
                fn (ReflectionNamedType $member): array => $member->getName() === 'null'
                    ? ['type' => 'null']
                    : $this->getBasicTypeSchema($this->typeName($member, $property)),
                $type->getTypes()
            );

            return $schema;
        }

        $typeName = $type instanceof ReflectionNamedType ? $this->typeName($type, $property) : null;

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

    /**
     * The type's name, with self resolved to the class declaring the property.
     */
    protected function typeName(ReflectionNamedType $type, ReflectionProperty $property): string
    {
        return $type->getName() === 'self' ? $property->getDeclaringClass()->getName() : $type->getName();
    }

    protected function processEnum(ReflectionEnum $enum): array
    {
        return [
            'type' => (string) $enum->getBackingType() === 'int' ? 'integer' : 'string',
            'enum' => array_map($this->schemaValue(...), $enum->getName()::cases()),
        ];
    }

    /**
     * A value as the schema writes it: an enum case by its backing value, or by its name when it has none.
     */
    protected function schemaValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => array_map($this->schemaValue(...), $value),
            default => $value,
        };
    }

    /**
     * The SchemaPropertiesInterface runtime map wins over the attribute.
     */
    protected function getPropertyAttribute(ReflectionClass $class, ReflectionProperty $property): ?SchemaProperty
    {
        return SchemaProperty::resolve($class, $property);
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

            case 'DateTime':
            case 'DateTimeImmutable':
            case 'DateTimeInterface':
                return ['type' => 'string', 'format' => 'date-time'];

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
     * @param string[] $types Class names
     * @throws ReflectionException
     * @throws StructuredOutputException
     */
    protected function generateAnyOfSchema(array $types): array
    {
        $schemas = [];

        // The Deserializer tells the items apart by the discriminator, which only an object carries
        foreach ($types as $type) {
            if (!class_exists($type)) {
                throw new StructuredOutputException("anyOf member '{$type}' is not a class");
            }

            if (enum_exists($type)) {
                throw new StructuredOutputException("anyOf member '{$type}' is an enum: an enum can only be the single anyOf member");
            }

            $shortName = strtolower(basename(str_replace('\\', '/', $type)));

            if (isset($schemas[$shortName])) {
                throw new StructuredOutputException("anyOf members share the discriminator value '{$shortName}': rename one of the classes");
            }

            $schemas[$shortName] = $this->injectDiscriminator($this->generateClassSchema($type), $shortName);
        }

        return ['anyOf' => array_values($schemas)];
    }

    /**
     * Inject a required discriminator field (lowercase class name) into object
     * schemas so the Deserializer can resolve the concrete anyOf type.
     *
     * @throws StructuredOutputException
     */
    protected function injectDiscriminator(array $schema, string $discriminatorValue): array
    {
        if (isset($schema['type']) && $schema['type'] === 'object') {
            $properties = (array) ($schema['properties'] ?? []);

            // The Deserializer takes the field away to pick the class, so a property of that name could never be filled
            if (isset($properties[$this->discriminator])) {
                throw new StructuredOutputException("The property '{$this->discriminator}' of anyOf member '{$discriminatorValue}' collides with the discriminator field");
            }

            $schema['properties'] = [
                $this->discriminator => [
                    'type' => 'string',
                    'enum' => [$discriminatorValue],
                    'description' => 'This property is mandatory and can only be filled with "'.$discriminatorValue.'". It is used as a discriminator for class type resolution.',
                ],
                ...$properties,
            ];

            $schema['required'] = [$this->discriminator, ...($schema['required'] ?? [])];
        }

        return $schema;
    }
}
