<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Schema;

use function preg_match;

final class DocumentField
{
    protected function __construct(
        protected string $name,
        protected DocumentFieldType $type,
        protected bool $required = false,
        protected bool $filterable = false,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->name)) {
            throw new DocumentSchemaException(
                "Document field \"{$this->name}\" must start with a letter or underscore and contain letters, numbers, or underscores only."
            );
        }
    }

    public static function string(string $name): self
    {
        return new self($name, DocumentFieldType::String);
    }

    public static function integer(string $name): self
    {
        return new self($name, DocumentFieldType::Integer);
    }

    public static function float(string $name): self
    {
        return new self($name, DocumentFieldType::Float);
    }

    public static function boolean(string $name): self
    {
        return new self($name, DocumentFieldType::Boolean);
    }

    public static function strings(string $name): self
    {
        return new self($name, DocumentFieldType::StringArray);
    }

    public static function integers(string $name): self
    {
        return new self($name, DocumentFieldType::IntegerArray);
    }

    public static function floats(string $name): self
    {
        return new self($name, DocumentFieldType::FloatArray);
    }

    public static function booleans(string $name): self
    {
        return new self($name, DocumentFieldType::BooleanArray);
    }

    public function required(bool $required = true): self
    {
        $field = clone $this;
        $field->required = $required;

        return $field;
    }

    public function filterable(bool $filterable = true): self
    {
        if ($filterable && $this->type->isArray()) {
            throw new DocumentSchemaException(
                "Array field \"{$this->name}\" cannot use portable filters yet. Use a raw backend filter instead."
            );
        }

        $field = clone $this;
        $field->filterable = $filterable;

        return $field;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): DocumentFieldType
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }
}
