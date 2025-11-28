<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\StaticConstructor;

use function is_null;

/**
 * @method static static make(string $name, PropertyType $type, string $description, bool $required = false, array $enum = [])
 */
class ToolProperty implements ToolPropertyInterface
{
    use StaticConstructor;

    public function __construct(
        protected string $name,
        protected PropertyType $type,
        protected ?string $description = null,
        protected bool $required = false,
        protected array $enum = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'enum' => $this->enum,
            'required' => $this->required,
        ];
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): PropertyType
    {
        return $this->type;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getEnum(): array
    {
        return $this->enum;
    }

    public function getJsonSchema(): array
    {
        $schema = [
            'type' => $this->type->value,
        ];

        if (!is_null($this->description)) {
            $schema['description'] = $this->description;
        }

        if ($this->enum !== []) {
            $schema['enum'] = $this->enum;
        }

        return $schema;
    }
}
