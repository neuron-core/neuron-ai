<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\StaticConstructor;

/**
 * @method static static make(string $name, string $description, bool $required = false, ?ToolPropertyInterface $items = null, ?int $minItems = null, ?int $maxItems = null)
 */
class ArrayProperty implements ToolPropertyInterface
{
    use StaticConstructor;

    protected PropertyType $type = PropertyType::ARRAY;

    /**
     * @throws ArrayPropertyException
     */
    public function __construct(
        protected string $name,
        protected ?string $description = null,
        protected bool $required = false,
        protected ?ToolPropertyInterface $items = null,
        protected ?int $minItems = null,
        protected ?int $maxItems = null,
    ) {
        $this->validateConstraints();
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            ...$this->getJsonSchema(),
            'required' => $this->required,
        ];
    }

    public function getJsonSchema(): array
    {
        $schema = [
            'type' => $this->type->value,
        ];

        if (!\is_null($this->description)) {
            $schema['description'] = $this->description;
        }

        if ($this->items instanceof ToolPropertyInterface) {
            $schema['items'] = $this->items->getJsonSchema();
        } else {
            $schema['items'] = ['type' => 'string'];
        }

        if ($this->minItems !== null && $this->minItems !== 0) {
            $schema['minItems'] = $this->minItems;
        }

        if ($this->maxItems !== null && $this->maxItems !== 0) {
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getItems(): ?ToolPropertyInterface
    {
        return $this->items;
    }

    /**
     * @throws ArrayPropertyException
     */
    protected function validateConstraints(): void
    {
        if ($this->minItems !== null && $this->minItems < 0) {
            throw new ArrayPropertyException("minItems must be >= 0, got {$this->minItems}");
        }

        if ($this->maxItems !== null && $this->maxItems < 0) {
            throw new ArrayPropertyException("maxItems must be >= 0, got {$this->maxItems}");
        }

        if ($this->minItems !== null && $this->maxItems !== null && $this->minItems > $this->maxItems) {
            throw new ArrayPropertyException(
                "minItems ({$this->minItems}) cannot be greater than maxItems ({$this->maxItems})"
            );
        }
    }
}
