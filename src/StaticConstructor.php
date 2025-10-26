<?php

declare(strict_types=1);

namespace NeuronAI;

trait StaticConstructor
{
    /**
     * Static constructor.
     */
    public static function make(...$arguments): static
    {
        // Handle named parameters by converting them to positional arguments
        if (count($arguments) === 1 && is_array($arguments[0]) && array_keys($arguments[0]) !== range(0, count($arguments[0]) - 1)) {
            // This is an associative array (named parameters)
            $namedArgs = $arguments[0];

            // Use reflection to map named parameters to constructor positions
            $reflection = new \ReflectionClass(static::class);
            $constructor = $reflection->getConstructor();

            if ($constructor) {
                $params = $constructor->getParameters();
                $positionalArgs = [];

                foreach ($params as $param) {
                    $paramName = $param->getName();
                    if (array_key_exists($paramName, $namedArgs)) {
                        $positionalArgs[] = $namedArgs[$paramName];
                    } else {
                        // Use default value if available
                        if ($param->isDefaultValueAvailable()) {
                            $positionalArgs[] = $param->getDefaultValue();
                        } else {
                            // Parameter is required but not provided
                            throw new \ArgumentCountError("Missing required parameter: {$paramName}");
                        }
                    }
                }

                /** @phpstan-ignore new.static */
                return new static(...$positionalArgs);
            }
        }

        /** @phpstan-ignore new.static */
        return new static(...$arguments);
    }
}
