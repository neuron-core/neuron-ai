<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class OutOfRange extends AbstractValidationRule
{
    public function __construct(protected int|float $min, protected int|float $max, protected bool $strict = false)
    {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($value < $this->min) {
            $violations[] = $this->buildMessage($name, 'must be greater than or equal to {compare}', ['compare' => $this->min]);
        } elseif ($this->strict && $value === $this->min) {
            $violations[] = $this->buildMessage($name, 'must be strictly greater than {compare}', ['compare' => $this->min]);
        }

        if ($value > $this->max) {
            $violations[] = $this->buildMessage($name, 'must be less than or equal to {compare}', ['compare' => $this->max]);
        } elseif ($this->strict && $value === $this->max) {
            $violations[] = $this->buildMessage($name, 'must be strictly less than {compare}', ['compare' => $this->max]);
        }
    }
}
