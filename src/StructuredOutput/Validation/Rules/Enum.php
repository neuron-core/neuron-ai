<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use NeuronAI\StructuredOutput\StructuredOutputException;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Enum extends AbstractValidationRule
{
    protected string $message = '{name} must be one of the following allowed values: {choices}.';

    /**
     * @param array|null $values List of allowed values. Cannot be used with `enum`.
     * @param string|null $class Enum class name to extract choices from. Cannot be used with `choices`.
     *
     * @throws StructuredOutputException if both `choices` and `enum` are provided or if `enum` is not valid.
     */
    public function __construct(
        protected ?array  $values = [],
        protected ?string $class = null,
    ) {
        if ($this->values !== null && $this->values !== [] && $this->class !== null) {
            throw new StructuredOutputException('You cannot provide both "choices" and "enum" options simultaneously. Please use only one.');
        }

        if (($this->values === null || $this->values === []) && $this->class === null) {
            throw new StructuredOutputException('Either option "choices" or "enum" must be given for validation rule "Enum"');
        }

        if ($this->values === null || $this->values === []) {
            $this->handleEnum();
        }
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        $value = $value instanceof \BackedEnum ? $value->value : $value;

        if (!\in_array($value, $this->values, true)) {
            $violations[] = $this->buildMessage($name, $this->message, ['choices' => \implode(", ", $this->values)]);
        }
    }

    /**
     * @throws StructuredOutputException
     */
    private function handleEnum(): void
    {
        if (!\enum_exists($this->class)) {
            throw new StructuredOutputException("Enum '{$this->class}' does not exist.");
        }

        if (!\is_subclass_of($this->class, \BackedEnum::class)) {
            throw new StructuredOutputException("Enum '{$this->class}' must implement BackedEnum.");
        }

        $this->values = \array_map(fn (\BackedEnum $case): int|string => $case->value, $this->class::cases());
    }
}
