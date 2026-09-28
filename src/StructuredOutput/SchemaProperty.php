<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

use Attribute;
use ReflectionClass;
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
     * Resolve the SchemaProperty for a property of the class being described.
     *
     * Runtime definitions from SchemaPropertiesInterface take precedence
     * over the attribute, allowing dynamic values (translations, config)
     * that PHP attribute arguments cannot express. For an inherited property,
     * the described class's map is asked before the map of the class declaring it.
     */
    public static function resolve(ReflectionClass $class, ReflectionProperty $property): ?self
    {
        $classes = $class->getName() === $property->class ? [$class] : [$class, $property->getDeclaringClass()];

        foreach ($classes as $candidate) {
            if ($candidate->implementsInterface(SchemaPropertiesInterface::class)) {
                /** @var class-string<SchemaPropertiesInterface> $className */
                $className = $candidate->getName();
                $schemaProperty = $className::schemaProperties()[$property->getName()] ?? null;
                if ($schemaProperty instanceof self) {
                    return $schemaProperty;
                }
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
    public static function isRequired(ReflectionClass $class, ReflectionProperty $property): bool
    {
        $required = self::resolve($class, $property)?->required;

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
