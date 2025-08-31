<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class InRange extends AbstractValidationRule
{
    public function __construct(protected int|float $min, protected int|float $max, protected bool $strict = false)
    {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($value < $this->min) {
            $violations[] = $this->buildMessage($name, 'must be greater or equals to {compare}', ['compare' => \get_debug_type($this->min)]);
        } elseif ($this->strict && $value === $this->min) {
            $violations[] = $this->buildMessage($name, 'must be strictly greater than {compare}', ['compare' => \get_debug_type($this->min)]);
        }

        if ($value > $this->max) {
            $violations[] = $this->buildMessage($name, 'must be lower than {compare}', ['compare' => \get_debug_type($this->max)]);
        } elseif ($this->strict && $value === $this->max) {
            $violations[] = $this->buildMessage($name, 'must be strictly lower than {compare}', ['compare' => \get_debug_type($this->max)]);
        }
    }
}
