<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Deserializer;

use BackedEnum;
use NeuronAI\ScalarCaster;
use NeuronAI\StaticConstructor;
use NeuronAI\StructuredOutput\SchemaProperty;
use DateTime;
use DateTimeImmutable;
use Exception;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

use function array_key_exists;
use function array_keys;
use function basename;
use function class_exists;
use function count;
use function enum_exists;
use function get_debug_type;
use function gettype;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function is_subclass_of;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function lcfirst;
use function preg_replace;
use function str_replace;
use function strtolower;
use function ucwords;

use const JSON_ERROR_NONE;

/**
 * @method static static make(string $discriminator = '__classname__')
 */
class Deserializer
{
    use StaticConstructor;

    public function __construct(protected string $discriminator = '__classname__')
    {
    }

    /**
     * @throws DeserializerException|ReflectionException
     */
    public function fromJson(string $jsonData, string $className): object
    {
        $data = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new DeserializerException('Invalid JSON: '.json_last_error_msg());
        }

        if (!is_array($data)) {
            throw $this->typeMismatch('The JSON', 'object', $data);
        }

        return $this->deserializeObject($data, $className);
    }

    /**
     * @throws DeserializerException|ReflectionException
     */
    protected function deserializeObject(array $data, string $className): object
    {
        if (!class_exists($className)) {
            throw new DeserializerException("Class {$className} does not exist");
        }

        $reflection = new ReflectionClass($className);

        $instance = $reflection->newInstanceWithoutConstructor();

        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        // Run a public zero-required-arg constructor so its initialization logic still executes
        $constructor = $reflection->getConstructor();
        $constructor = $constructor?->isPublic() && $constructor->getNumberOfRequiredParameters() === 0 ? $constructor : null;

        $promotedArgs = [];
        $readonlyValues = [];

        foreach ($properties as $property) {
            // Model output fills only what the schema describes, never non-public or static state
            if ($property->isStatic()) {
                continue;
            }

            $propertyName = $property->getName();
            $key = $this->findPropertyKey($data, $propertyName);
            $type = $property->getType();
            $nullable = $type?->allowsNull() ?? true;

            // Left out, or null where the property can't hold it: a required one is the model's to fix, an optional one gets its default
            if ($key === null || ($data[$key] === null && !$nullable)) {
                if (SchemaProperty::isRequired($property)) {
                    throw new DeserializerException($key === null ? "Property \"{$propertyName}\" is required" : "Property \"{$propertyName}\" must not be null");
                }

                continue;
            }

            $value = $data[$key];

            if ($value !== null && $type) {
                $value = $this->castValue($value, $type, $property);
            }

            // One write per property: the constructor assigns what it promotes, and a readonly
            // property waits for it, since the constructor may assign that one too
            if ($property->isPromoted() && $property->class === $constructor?->class) {
                $promotedArgs[ $propertyName ] = $value;
            } elseif ($constructor !== null && $property->isReadOnly()) {
                $readonlyValues[] = [$property, $value];
            } else {
                $property->setValue($instance, $value);
            }
        }

        if ($constructor !== null) {
            $constructor->invokeArgs($instance, $promotedArgs);

            foreach ($readonlyValues as [$property, $value]) {
                if (!$property->isInitialized($instance)) {
                    $property->setValue($instance, $value);
                }
            }
        }

        $this->applyDefaults($instance, $properties);

        return $instance;
    }

    /**
     * What the model left out gets its default, or null when it has none but can hold one.
     *
     * @param ReflectionProperty[] $properties
     */
    protected function applyDefaults(object $instance, array $properties): void
    {
        foreach ($properties as $property) {
            if ($property->isStatic() || $property->isInitialized($instance)) {
                continue;
            }

            // Only a constructor applies a promoted default, and it may not have run
            $parameter = $property->isPromoted() ? new ReflectionParameter([$property->class, '__construct'], $property->name) : null;

            if ($parameter?->isDefaultValueAvailable()) {
                $property->setValue($instance, $parameter->getDefaultValue());
            } elseif ($property->getType()?->allowsNull()) {
                $property->setValue($instance, null);
            }
        }
    }

    /**
     * Matches the exact key first, then snake_case and camelCase variants.
     */
    protected function findPropertyKey(array $data, string $propertyName): ?string
    {
        if (array_key_exists($propertyName, $data)) {
            return $propertyName;
        }

        $snakeCase = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyName));
        if (array_key_exists($snakeCase, $data)) {
            return $snakeCase;
        }

        $camelCase = lcfirst(str_replace('_', '', ucwords($propertyName, '_')));
        if (array_key_exists($camelCase, $data)) {
            return $camelCase;
        }

        return null;
    }

    /**
     * @throws DeserializerException|ReflectionException
     */
    protected function castValue(mixed $value, ReflectionType $type, ReflectionProperty $property): mixed
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                try {
                    return $this->castToSingleType($value, $unionType, $property);
                } catch (Exception) {
                    continue;
                }
            }
            throw new DeserializerException("Cannot cast value to any type in union for property {$property->getName()}");
        }

        // @phpstan-ignore-next-line
        return $this->castToSingleType($value, $type, $property);
    }

    /**
     * @throws DeserializerException|ReflectionException
     */
    protected function castToSingleType(
        mixed $value,
        ReflectionNamedType $type,
        ReflectionProperty $property
    ): mixed {
        $typeName = $type->getName();

        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }
            throw new DeserializerException("Property {$property->getName()} does not allow null values");
        }

        return match ($typeName) {
            'string' => $this->castScalar($value, 'string', $property),
            'int' => $this->castScalar($value, 'integer', $property),
            'float' => $this->castScalar($value, 'number', $property),
            'bool' => $this->castScalar($value, 'boolean', $property),
            'array' => $this->handleArray($value, $property),
            'DateTime' => $this->createDateTime($value),
            'DateTimeImmutable' => $this->createDateTimeImmutable($value),
            default => $this->handleSingleObject($value, $typeName, $property)
        };
    }

    /**
     * @param 'integer'|'number'|'string'|'boolean' $type
     * @throws DeserializerException
     */
    protected function castScalar(mixed $value, string $type, ReflectionProperty $property): int|float|string|bool
    {
        return ScalarCaster::cast($value, $type)
            ?? throw $this->typeMismatch("Property \"{$property->getName()}\"", $type, $value);
    }

    /**
     * @throws DeserializerException|ReflectionException
     */
    protected function handleSingleObject(mixed $value, string $typeName, ReflectionProperty $property): mixed
    {
        // class_exists() is true for an enum too
        if (enum_exists($typeName)) {
            return $this->handleEnum($typeName, $value);
        }

        if (class_exists($typeName)) {
            if (!is_array($value)) {
                throw $this->typeMismatch("Property \"{$property->getName()}\"", 'object', $value);
            }

            return $this->deserializeObject($value, $typeName);
        }

        return $value;
    }

    /**
     * @throws DeserializerException|ReflectionException
     */
    protected function handleArray(mixed $value, ReflectionProperty $property): array
    {
        if (!is_array($value)) {
            throw $this->typeMismatch("Property \"{$property->getName()}\"", 'array', $value);
        }

        $types = SchemaProperty::resolve($property)->anyOf ?? [];

        if ($types === [] || (count($types) === 1 && !class_exists($types[0]))) {
            return $value;
        }

        foreach ($value as $index => $item) {
            $value[$index] = $this->deserializeItem($item, $types, "Property \"{$property->getName()}\" element {$index}");
        }

        return $value;
    }

    /**
     * @param string[] $types
     * @throws DeserializerException|ReflectionException
     */
    protected function deserializeItem(mixed $item, array $types, string $subject): object
    {
        // class_exists() is true for an enum too
        if (count($types) === 1 && enum_exists($types[0])) {
            return $this->handleEnum($types[0], $item);
        }

        if (!is_array($item)) {
            throw $this->typeMismatch($subject, 'object', $item);
        }

        return count($types) === 1
            ? $this->deserializeObject($item, $types[0])
            : $this->deserializeObjectWithDiscriminator($item, $types);
    }

    protected function typeMismatch(string $subject, string $type, mixed $value): DeserializerException
    {
        return new DeserializerException("{$subject} must be of type {$type}, " . get_debug_type($value) . ' given');
    }

    /**
     * Resolve the concrete class of a multi-type (anyOf) item through the
     * discriminator field injected by JsonSchema.
     *
     * @throws DeserializerException|ReflectionException
     */
    protected function deserializeObjectWithDiscriminator(array $data, array $possibleTypes): object
    {
        if (!isset($data[$this->discriminator])) {
            throw new DeserializerException("Missing {$this->discriminator} discriminator field in data for multi-type array deserialization");
        }

        $discriminatorValue = strtolower((string) $data[$this->discriminator]);

        $mapping = [];
        foreach ($possibleTypes as $type) {
            $shortName = strtolower(basename(str_replace('\\', '/', $type)));
            $mapping[$shortName] = $type;
        }

        if (!isset($mapping[$discriminatorValue])) {
            throw new DeserializerException("Unknown discriminator value '{$discriminatorValue}'. Expected one of: " . implode(', ', array_keys($mapping)));
        }

        $className = $mapping[$discriminatorValue];

        // The discriminator is synthetic — it must not reach the target class
        unset($data[$this->discriminator]);

        return $this->deserializeObject($data, $className);
    }

    /**
     * @throws DeserializerException
     */
    protected function createDateTime(mixed $value): DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if (is_string($value)) {
            try {
                return new DateTime($value);
            } catch (Exception) {
                throw new DeserializerException("Cannot create DateTime from: {$value}");
            }
        }

        if (is_numeric($value)) {
            return new DateTime('@'.$value);
        }

        throw new DeserializerException("Cannot create DateTime from value type: ".gettype($value));
    }

    /**
     * @throws DeserializerException
     */
    protected function createDateTimeImmutable(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value)) {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                throw new DeserializerException("Cannot create DateTimeImmutable from: {$value}");
            }
        }

        if (is_numeric($value)) {
            return new DateTimeImmutable('@'.$value);
        }

        throw new DeserializerException("Cannot create DateTimeImmutable from value type: ".gettype($value));
    }

    /**
     * @throws DeserializerException
     */
    protected function handleEnum(BackedEnum|string $typeName, mixed $value): BackedEnum
    {
        if (!is_subclass_of($typeName, BackedEnum::class)) {
            throw new DeserializerException("Cannot create BackedEnum from: {$typeName}");
        }

        // Read the value as the backing type first, so "1" finds the case backed by 1
        $backingType = (string) (new ReflectionEnum($typeName))->getBackingType() === 'int' ? 'integer' : 'string';
        $backingValue = ScalarCaster::cast($value, $backingType);

        $enum = $backingValue === null ? null : $typeName::tryFrom($backingValue);

        if (!$enum instanceof BackedEnum) {
            $spelling = is_string($value) ? $value : json_encode($value);
            throw new DeserializerException("Invalid enum value '{$spelling}' for {$typeName}");
        }

        return $enum;
    }
}
