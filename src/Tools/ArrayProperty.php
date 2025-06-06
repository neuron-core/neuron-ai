<?php

namespace NeuronAI\Tools;

class ArrayProperty implements ToolPropertyInterface
{
    protected PropertyType $type = PropertyType::ARRAY;

    public function __construct(
        protected string $name,
        protected string $description,
        protected bool $required = false,
        protected ?ToolPropertyInterface $items = null,
        protected ?int $minItems = null,
        protected ?int $maxItems = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'items' => $this->getJsonSchema(),
            'required' => $this->required,
        ];
    }

    public function getJsonSchema(): array
    {
        $schema = [
            'type' => $this->type->value,
            'description' => $this->description,
        ];

        if (!empty($this->items)) {
            $schema['items'] = $this->items->getJsonSchema();
        }

        if (isset($this->minItems)) {
            $schema['minItems'] = $this->minItems;
        }

        if (isset($this->maxItems)) {
            $schema['maxItems'] = $this->maxItems;
        }

        return $schema;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getItems(): ?ToolPropertyInterface
    {
        return $this->items;
    }
}
