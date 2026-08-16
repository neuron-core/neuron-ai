<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Schema;

enum DocumentFieldType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case StringArray = 'string[]';
    case IntegerArray = 'integer[]';
    case FloatArray = 'float[]';
    case BooleanArray = 'boolean[]';

    public function isNumeric(): bool
    {
        return $this === self::Integer || $this === self::Float;
    }

    public function isArray(): bool
    {
        return match ($this) {
            self::StringArray, self::IntegerArray, self::FloatArray, self::BooleanArray => true,
            default => false,
        };
    }
}
