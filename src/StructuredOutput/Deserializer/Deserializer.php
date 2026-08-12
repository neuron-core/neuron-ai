<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Deserializer;

use BackedEnum;
use NeuronAI\StaticConstructor;
use NeuronAI\StructuredOutput\SchemaProperty;
use DateTime;
use DateTimeImmutable;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

use function array_key_exists;
use function array_keys;
use function array_map;
use function basename;
use function class_exists;
use function count;
use function enum_exists;
use function gettype;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function is_subclass_of;
use function json_decode;
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

        $properties = $reflection->getProperties();

        $promotedArgs = [];

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            $value = $this->findPropertyValue($data, $propertyName);

            if ($value !== null) {
                $type = $property->getType();

                if ($type) {
                    $value = $this->castValue($value, $type, $property);
                }

                $property->setValue($instance, $value);

                if ($property->isPromoted()) {
                    $promotedArgs[ $propertyName ] = $value;
                }
            }
        }

        // Run a public zero-required-arg constructor so its initialization logic still executes
        $constructor = $reflection->getConstructor();
        if ($constructor && $constructor->isPublic() && $constructor->getNumberOfRequiredParameters() === 0) {
            $constructor->invokeArgs($instance, $promotedArgs);
        }

        return $instance;
    }

    /**
     * Matches the exact key first, then snake_case and camelCase variants.
     */
    protected function findPropertyValue(array $data, string $propertyName): mixed
    {
        if (array_key_exists($propertyName, $data)) {
            return $data[$propertyName];
        }

        $snakeCase = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyName));
        if (array_key_exists($snakeCase, $data)) {
            return $data[$snakeCase];
        }

        $camelCase = lcfirst(str_replace('_', '', ucwords($propertyName, '_')));
        if (array_key_exists($camelCase, $data)) {
            return $data[$camelCase];
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
            'string' => (string) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'array' => $this->handleArray($value, $property),
            'DateTime' => $this->createDateTime($value),
            'DateTimeImmutable' => $this->createDateTimeImmutable($value),
            default => $this->handleSingleObject($value, $typeName)
        };
    }

    /**
     * @throws DeserializerException|ReflectionException
     */
    protected function handleSingleObject(mixed $value, string $typeName): mixed
    {
        if (is_array($value) && class_exists($typeName)) {
            return $this->deserializeObject($value, $typeName);
        }

        if (enum_exists($typeName)) {
            return $this->handleEnum($typeName, $value);
        }

        return $value;
    }

    /**
     * @throws DeserializerException|ReflectionException
     */
    protected function handleArray(mixed $value, ReflectionProperty $property): mixed
    {
        $attribute = SchemaProperty::resolve($property);

        if ($attribute instanceof SchemaProperty) {
            if ($attribute->anyOf !== null && $attribute->anyOf !== []) {
                if (count($attribute->anyOf) === 1) {
                    $elementType = $attribute->anyOf[0];
                    if (class_exists($elementType)) {
                        return array_map(fn (array $item): object => $this->deserializeObject($item, $elementType), $value);
                    }
                } else {
                    return array_map(fn (array $item): object => $this->deserializeObjectWithDiscriminator($item, $attribute->anyOf), $value);
                }
            }
        }

        return $value;
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

    protected function handleEnum(BackedEnum|string $typeName, mixed $value): BackedEnum
    {
        if (!is_subclass_of($typeName, BackedEnum::class)) {
            throw new DeserializerException("Cannot create BackedEnum from: {$typeName}");
        }

        $enum = $typeName::tryFrom($value);

        if (!$enum instanceof BackedEnum) {
            throw new DeserializerException("Invalid enum value '{$value}' for {$typeName}");
        }

        return $enum;
    }
}
