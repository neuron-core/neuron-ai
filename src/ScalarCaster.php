<?php

declare(strict_types=1);

namespace NeuronAI;

use function filter_var;
use function is_bool;
use function is_scalar;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOL;
use const FILTER_VALIDATE_FLOAT;
use const FILTER_VALIDATE_INT;

/**
 * Models often send a JSON value in the wrong type: "5" for an integer, "false" for a boolean.
 * A value converts only when nothing is lost, so "5" is an integer but 2.7 isn't, and "false"
 * is false but "maybe" isn't a boolean. Null means the value can't be read as that type.
 */
class ScalarCaster
{
    /**
     * @param 'integer'|'number'|'string'|'boolean' $type A JSON Schema scalar type
     */
    public static function cast(mixed $value, string $type): int|float|string|bool|null
    {
        // filter_var() would read null as false, true as 1 and false as an empty string
        if (!is_scalar($value) || (is_bool($value) && $type !== 'boolean')) {
            return null;
        }

        return match ($type) {
            'integer' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'number' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            'string' => (string) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
        };
    }
}
