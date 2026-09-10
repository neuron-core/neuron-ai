<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\StaticConstructor;

use function filter_var;
use function get_debug_type;
use function is_bool;
use function is_null;
use function is_scalar;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOL;
use const FILTER_VALIDATE_FLOAT;
use const FILTER_VALIDATE_INT;

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
        if ($input === null) {
            return null;
        }

        // A boolean is only a boolean: filter_var() would read true as 1 and false as an empty string
        $value = is_bool($input) && $this->type !== PropertyType::BOOLEAN ? null : match ($this->type) {
            PropertyType::INTEGER => filter_var($input, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            PropertyType::NUMBER => filter_var($input, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? filter_var($input, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            PropertyType::STRING => is_scalar($input) ? (string) $input : null,
            PropertyType::BOOLEAN => filter_var($input, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            default => $input,
        };

        return $value ?? throw new InvalidToolInput("must be of type {$this->type->value}, " . get_debug_type($input) . ' given');
    }
}
