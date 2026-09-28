<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\SchemaProperty;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

use function is_object;
use function spl_object_id;

class Validator
{
    /**
     * Objects being validated along the current path, so a cycle stops where it closes.
     *
     * @var array<int, true>
     */
    protected static array $validating = [];

    /**
     * Validate an object, and every object its public properties hold, against their rules
     *
     * @return array<int, string>
     * @throws ReflectionException
     */
    public static function validate(mixed $obj): array
    {
        return static::validateObject($obj, '');
    }

    /**
     * @param string $path Where the object sits in the root, prefixed to its property names
     * @return array<int, string>
     * @throws ReflectionException
     */
    protected static function validateObject(object $obj, string $path): array
    {
        $id = spl_object_id($obj);

        // ArrayOf re-enters validate() for list items, so the guard is shared across calls
        if (isset(static::$validating[$id])) {
            return [];
        }

        static::$validating[$id] = true;

        try {
            $reflection = new ReflectionClass($obj);
            $violations = [];

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                // Get the value of the property
                $name = $path.$property->getName();
                $value = $property->isInitialized($obj) ? $property->getValue($obj) : null;

                // A missing optional property is valid: whether a value is due is the schema's call, as for the Deserializer
                if ($value === null && !SchemaProperty::isRequired($reflection, $property)) {
                    continue;
                }

                // Apply all the validation rules to the value; other attributes are never instantiated
                foreach ($property->getAttributes(ValidationRuleInterface::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $attribute->newInstance()->validate($name, $value, $violations);
                }

                // A nested object is held to its own class's rules, reported under its path
                if (is_object($value)) {
                    $violations = [...$violations, ...static::validateObject($value, "{$name}.")];
                }
            }

            return $violations;
        } finally {
            unset(static::$validating[$id]);
        }
    }
}
