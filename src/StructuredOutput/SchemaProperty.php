<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

use Attribute;
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
}
