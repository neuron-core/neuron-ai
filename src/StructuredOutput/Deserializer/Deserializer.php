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
     * Deserialize JSON data into a specified class instance
     *
     * @return object Instance of the specified class
     * @throws DeserializerException|ReflectionException
     */
    public function fromJson(string $jsonData, string $className): object
    {
        // Decode JSON data
        $data = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new DeserializerException('Invalid JSON: '.json_last_error_msg());
        }

        return $this->deserializeObject($data, $className);
    }

    /**
     * Deserialize an array/object into a class instance
     *
     * @param  array  $data  The data to deserialize
     * @param  string  $className  The target class name
     * @throws DeserializerException|ReflectionException
     */
    protected function deserializeObject(array $data, string $className): object
    {
        if (!class_exists($className)) {
            throw new DeserializerException("Class {$className} does not exist");
        }

        $reflection = new ReflectionClass($className);

        // Create an instance without calling constructor
        $instance = $reflection->newInstanceWithoutConstructor();

        // Get all properties including private/protected
        $properties = $reflection->getProperties();

        // Track values set on promoted properties
        $promotedArgs = [];

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            // Check if data contains this property (case-sensitive and snake_case/camelCase variants)
            $value = $this->findPropertyValue($data, $propertyName);

            if ($value !== null) {
                // Get property type information
                $type = $property->getType();

                if ($type) {
                    $value = $this->castValue($value, $type, $property);
                }

                $property->setValue($instance, $value);

                // Track any promoted arguments that are being set
                if ($property->isPromoted()) {
                    $promotedArgs[ $propertyName ] = $value;
                }
            }
        }

        // Call constructor if it exists and is public
        $constructor = $reflection->getConstructor();
        if ($constructor && $constructor->isPublic() && $constructor->getNumberOfRequiredParameters() === 0) {
            $constructor->invokeArgs($instance, $promotedArgs);
        }

        return $instance;
    }

    /**
     * Find property value in data, supporting different naming conventions
     */
    protected function findPropertyValue(array $data, string $propertyName): mixed
    {
        // Direct match
        if (array_key_exists($propertyName, $data)) {
            return $data[$propertyName];
        }

        // Convert camelCase to snake_case
        $snakeCase = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyName));
        if (array_key_exists($snakeCase, $data)) {
            return $data[$snakeCase];
        }

        // Convert snake_case to camelCase
        $camelCase = lcfirst(str_replace('_', '', ucwords($propertyName, '_')));
        if (array_key_exists($camelCase, $data)) {
            return $data[$camelCase];
        }

        return null;
    }

    /**
     * Cast value to the appropriate type based on property type
     *
     * @throws DeserializerException|ReflectionException
     */
    protected function castValue(mixed $value, ReflectionType $type, ReflectionProperty $property): mixed
    {
        if ($type instanceof ReflectionUnionType) {
            // Handle union types
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
     * Cast value to a single type
     *
     * @throws DeserializerException|ReflectionException
     */
    protected function castToSingleType(
        mixed $value,
        ReflectionNamedType $type,
        ReflectionProperty $property
    ): mixed {
        $typeName = $type->getName();

        // Handle null values
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

        // Fallback: return the value as-is
        return $value;
    }

    /**
     * Handle collections
     *
     * @throws DeserializerException|ReflectionException
     */
    protected function handleArray(mixed $value, ReflectionProperty $property): mixed
    {
        $attributes = $property->getAttributes(SchemaProperty::class);

        if ($attributes !== []) {
            /** @var SchemaProperty $attribute */
            $attribute = $attributes[0]->newInstance();

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

        // Fallback: return the value as-is
        return $value;
    }

    /**
     * Deserialize an object using a discriminator field to determine the class
     *
     * @return object Deserialized object instance
     * @throws DeserializerException|ReflectionException
     */
    protected function deserializeObjectWithDiscriminator(array $data, array $possibleTypes): object
    {
        // Check for the discriminator field
        if (!isset($data[$this->discriminator])) {
            throw new DeserializerException("Missing {$this->discriminator} discriminator field in data for multi-type array deserialization");
        }

        $discriminatorValue = strtolower((string) $data[$this->discriminator]);

        // Build mapping: lowercase classname => fully qualified class name
        $mapping = [];
        foreach ($possibleTypes as $type) {
            $shortName = strtolower(basename(str_replace('\\', '/', $type)));
            $mapping[$shortName] = $type;
        }

        // Find a matching class
        if (!isset($mapping[$discriminatorValue])) {
            throw new DeserializerException("Unknown discriminator value '{$discriminatorValue}'. Expected one of: " . implode(', ', array_keys($mapping)));
        }

        $className = $mapping[$discriminatorValue];

        // Remove discriminator field from data before deserialization
        unset($data[$this->discriminator]);

        // Deserialize into the correct class
        return $this->deserializeObject($data, $className);
    }

    /**
     * Create a DateTime object from various input formats
     *
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
     * Create a DateTimeImmutable object from various input formats
     *
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
