<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

use Attribute;
use ReflectionParameter;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY)]
class SchemaProperty
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?bool $required = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?array $anyOf = null,
    ) {
    }

    /**
     * Resolve the SchemaProperty for a class property.
     *
     * Runtime definitions from SchemaPropertiesInterface take precedence
     * over the attribute, allowing dynamic values (translations, config)
     * that PHP attribute arguments cannot express.
     */
    public static function resolve(ReflectionProperty $property): ?self
    {
        $class = $property->getDeclaringClass();

        if ($class->implementsInterface(SchemaPropertiesInterface::class)) {
            /** @var class-string<SchemaPropertiesInterface> $className */
            $className = $class->getName();
            $schemaProperty = $className::schemaProperties()[$property->getName()] ?? null;
            if ($schemaProperty instanceof self) {
                return $schemaProperty;
            }
        }

        $attributes = $property->getAttributes(self::class);
        if ($attributes !== []) {
            return $attributes[0]->newInstance();
        }

        return null;
    }

    /**
     * Whether the model must send the property: the one rule JsonSchema advertises and
     * Deserializer enforces. A required flag decides; without one, a property is required
     * when it can't be null and has no default.
     */
    public static function isRequired(ReflectionProperty $property): bool
    {
        $required = self::resolve($property)?->required;

        if ($required !== null) {
            return $required;
        }

        $nullable = $property->getType()?->allowsNull() ?? true;

        // A promoted property's default lives on its constructor parameter
        $promotedDefault = $property->isPromoted()
            && (new ReflectionParameter([$property->class, '__construct'], $property->name))->isDefaultValueAvailable();

        return !$nullable && !$property->hasDefaultValue() && !$promotedDefault;
    }
}
