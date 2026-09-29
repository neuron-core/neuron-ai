<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\ScalarCaster;
use NeuronAI\StaticConstructor;

use function array_map;
use function get_debug_type;
use function implode;
use function in_array;
use function is_null;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @method static static make(string $name, PropertyType $type, string $description, bool $required = false, array $enum = [], bool $nullable = false)
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
        protected bool $nullable = false,
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
            'nullable' => $this->nullable,
        ];
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
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
            'type' => $this->nullable
                ? [$this->type->value, 'null']
                : $this->type->value,
        ];

        if (!is_null($this->description)) {
            $schema['description'] = $this->description;
        }

        if ($this->enum !== []) {
            $schema['enum'] = $this->enum;
        }

        return $schema;
    }

    public function cast(mixed $input): mixed
    {
        if ($input === null || $this->type === PropertyType::ARRAY || $this->type === PropertyType::OBJECT) {
            return $input;
        }

        $value = ScalarCaster::cast($input, $this->type->value)
            ?? throw new InvalidToolInput("must be of type {$this->type->value}, " . get_debug_type($input) . ' given');

        if ($this->enum !== [] && !in_array($value, $this->enum, true)) {
            throw new InvalidToolInput('must be one of ' . $this->quote($this->enum) . '; ' . $this->quote([$value]) . ' given');
        }

        return $value;
    }

    /**
     * @param array<int|float|string|bool> $values
     */
    protected function quote(array $values): string
    {
        return implode(', ', array_map(fn (int|float|string|bool $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $values));
    }
}
