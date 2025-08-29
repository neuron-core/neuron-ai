<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class NotEqualTo extends AbstractValidationRule
{
    public function __construct(protected mixed $reference)
    {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($value === $this->reference) {
            $violations[] = $this->buildMessage($name, 'must not be equal to {compare}', ['compare' => \get_debug_type($this->reference)]);
        }
    }
}
