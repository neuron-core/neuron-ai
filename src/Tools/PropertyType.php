<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Exceptions\ToolException;
use Throwable;

use function is_string;

enum PropertyType: string
{
    case INTEGER = 'integer';
    case STRING = 'string';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case ARRAY = 'array';
    case OBJECT = 'object';

    /**
     * Get the scalar type of the property from the array syntax like ["string", "null"]
     *
     * @throws ToolException
     */
    public static function fromSchema(array|string $schema): PropertyType
    {
        if (is_string($schema)) {
            return PropertyType::from($schema);
        }

        foreach ($schema as $type) {
            try {
                return PropertyType::from($type);
            } catch (Throwable) {
            }
        }

        throw new ToolException("Property type not valid.");
    }
}
