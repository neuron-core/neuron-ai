<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

/**
 * Allows a structured output class to define its schema properties at runtime,
 * overcoming the constant-expression limitation of PHP attributes
 * (e.g. translated descriptions, config-driven constraints).
 *
 * Entries returned by schemaProperties() take precedence over the
 * SchemaProperty attribute for the same property. Properties without
 * an entry fall back to their attribute, if any.
 */
interface SchemaPropertiesInterface
{
    /**
     * @return array<string, SchemaProperty> Keyed by property name.
     */
    public static function schemaProperties(): array;
}
