<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation;

class Validator
{
    /**
     * Validate an object
     *
     * @return array<int, string>
     * @throws \ReflectionException
     */
    public static function validate(mixed $obj): array
    {
        $reflection = new \ReflectionClass($obj);
        $violations = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            // Get all attributes for this property
            $attributes = $property->getAttributes();

            if (empty($attributes)) {
                continue;
            }

            // Get the value of the property
            $name = $property->getName();
            $value = $property->isInitialized($obj) ? $property->getValue($obj) : null;

            // Apply all the validation rules to the value
            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();

                // Perform validation
                if ($instance instanceof ValidationRuleInterface) {
                    $instance->validate($name, $value, $violations);
                }
            }
        }

        return $violations;
    }
}
