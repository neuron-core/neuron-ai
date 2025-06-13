<?php

namespace NeuronAI\StructuredOutput\Deserializer;

class Deserializer
{
    /**
     * Deserialize JSON data into a specified class instance
     *
     * @param string $jsonData The JSON string to deserialize
     * @param string $className The fully qualified class name to instantiate
     * @return mixed Instance of the specified class
     * @throws DeserializerException|\ReflectionException
     */
    public static function fromJson(string $jsonData, string $className): mixed
    {
        // Decode JSON data
        $data = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new DeserializerException('Invalid JSON: ' . json_last_error_msg());
        }

        return self::deserializeObject($data, $className);
    }

    /**
     * Deserialize an array/object into a class instance
     *
     * @param array $data The data to deserialize
     * @param string $className The target class name
     * @return mixed
     * @throws DeserializerException|\ReflectionException
     */
    private static function deserializeObject(array $data, string $className): mixed
    {
        if (!class_exists($className)) {
            throw new DeserializerException("Class {$className} does not exist");
        }

        $reflection = new \ReflectionClass($className);

        // Create an instance without calling constructor
        $instance = $reflection->newInstanceWithoutConstructor();

        // Get all properties including private/protected
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            // Check if data contains this property (case-sensitive and snake_case/camelCase variants)
            $value = self::findPropertyValue($data, $propertyName);

            if ($value !== null) {
                // Get property type information
                $type = $property->getType();

                if ($type) {
                    $value = self::castValue($value, $type, $property);
                }

                $property->setValue($instance, $value);
            }
        }

        // Call constructor if it exists and is public
        $constructor = $reflection->getConstructor();
        if ($constructor && $constructor->isPublic() && $constructor->getNumberOfRequiredParameters() === 0) {
            $constructor->invoke($instance);
        }

        return $instance;
    }

    /**
     * Find property value in data, supporting different naming conventions
     *
     * @param array $data
     * @param string $propertyName
     * @return mixed
     */
    private static function findPropertyValue(array $data, string $propertyName): mixed
    {
        // Direct match
        if (array_key_exists($propertyName, $data)) {
            return $data[$propertyName];
        }

        // Convert camelCase to snake_case
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyName));
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
     * @throws DeserializerException|\ReflectionException
     */
    private static function castValue(mixed $value, \ReflectionType $type, \ReflectionProperty $property): mixed
    {
        if ($type instanceof \ReflectionUnionType) {
            // Handle union types (PHP 8+)
            foreach ($type->getTypes() as $unionType) {
                try {
                    return self::castToSingleType($value, $unionType, $property);
                } catch (\Exception $e) {
                    continue;
                }
            }
            throw new DeserializerException("Cannot cast value to any type in union for property {$property->getName()}");
        }

        // @phpstan-ignore-next-line
        return self::castToSingleType($value, $type, $property);
    }

    /**
     * Cast value to a single type
     *
     * @throws DeserializerException|\ReflectionException
     */
    private static function castToSingleType(mixed $value, \ReflectionNamedType $type, \ReflectionProperty $property): mixed
    {
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
            'array' => self::handleArray($value, $typeName, $property),
            'DateTime' => self::createDateTime($value),
            'DateTimeImmutable' => self::createDateTimeImmutable($value),
            default => self::handleSingleObject($value, $typeName, $property)
        };
    }

    /**
     * @throws DeserializerException|\ReflectionException
     */
    private static function handleSingleObject(mixed $value, string $typeName, \ReflectionProperty $property): mixed
    {
        if (is_array($value) && class_exists($typeName)) {
            return self::deserializeObject($value, $typeName);
        }

        // If it's already the correct type, return as-is
        if (is_object($value) && get_class($value) === $typeName) {
            return $value;
        }

        // Fallback: return the value as-is
        return $value;
    }

    /**
     * Handle collections
     *
     * @throws DeserializerException|\ReflectionException
     */
    private static function handleArray(mixed $value, string $typeName, \ReflectionProperty $property): mixed
    {
        // Handle arrays of objects using docblock annotations
        if (self::isArrayOfObjects($property)) {
            $elementType = self::getArrayElementType($property);
            if ($elementType && class_exists($elementType)) {
                return array_map(fn ($item) => self::deserializeObject($item, $elementType), $value);
            }
        }

        // Fallback: return the value as-is
        return $value;
    }

    /**
     * Check if a property represents an array of objects based on docblock
     */
    private static function isArrayOfObjects(\ReflectionProperty $property): bool
    {
        $docComment = $property->getDocComment();
        if (!$docComment) {
            return false;
        }

        return preg_match('/@var\s+([a-zA-Z_\\\\]+)\[\]/', $docComment) === 1;
    }

    /**
     * Extract an element type from array docblock annotation
     */
    private static function getArrayElementType(\ReflectionProperty $property): ?string
    {
        $docComment = $property->getDocComment();
        if (!$docComment) {
            return null;
        }

        if (preg_match('/@var\s+([a-zA-Z_\\\\]+)\[\]/', $docComment, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Create a DateTime object from various input formats
     *
     * @throws DeserializerException
     */
    private static function createDateTime(mixed $value): \DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }

        if (is_string($value)) {
            try {
                return new \DateTime($value);
            } catch (\Exception $e) {
                throw new DeserializerException("Cannot create DateTime from: {$value}");
            }
        }

        if (is_numeric($value)) {
            return new \DateTime('@' . $value);
        }

        throw new DeserializerException("Cannot create DateTime from value type: " . gettype($value));
    }

    /**
     * Create a DateTimeImmutable object from various input formats
     *
     * @throws DeserializerException
     */
    private static function createDateTimeImmutable(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (is_string($value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception $e) {
                throw new DeserializerException("Cannot create DateTimeImmutable from: {$value}");
            }
        }

        if (is_numeric($value)) {
            return new \DateTimeImmutable('@' . $value);
        }

        throw new DeserializerException("Cannot create DateTimeImmutable from value type: " . gettype($value));
    }
}
