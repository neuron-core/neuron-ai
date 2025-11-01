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

        // Check if arguments represent named parameters (associative array)
        if (!empty($arguments) && isset($arguments[0]) && is_array($arguments[0])) {
            // Case 1: Single array argument (Tool::make(['name' => 'test', 'desc' => 'desc']))
            $namedArgs = $arguments[0];

            if (array_keys($namedArgs) !== range(0, count($namedArgs) - 1)) {
                // This is an associative array (named parameters)
                return self::createFromNamedArgs($namedArgs);
            }

            // If it's a sequential array, treat as positional arguments
            /** @phpstan-ignore new.static */
            return new static(...$namedArgs);
        }

        // Case 2: Named parameters passed directly (Tool::make(name: 'test', desc: 'desc'))
        // In this case, $arguments itself is an associative array
        if (self::isAssociativeArray($arguments)) {
            return self::createFromNamedArgs($arguments);
        }

        // Case 3: Regular positional parameters
        /** @phpstan-ignore new.static */
        return new static(...$arguments);
    }

    /**
     * Check if an array is associative (has string keys)
     */
    private static function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Create instance from named arguments using reflection
     */
    private static function createFromNamedArgs(array $namedArgs): static
    {
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

        // Fallback: try to pass named args as-is (will likely fail but better than nothing)
        /** @phpstan-ignore new.static */
        return new static(...$namedArgs);
    }
}
