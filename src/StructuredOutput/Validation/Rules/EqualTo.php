<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use Attribute;

use function json_encode;

#[Attribute(Attribute::TARGET_PROPERTY)]
class EqualTo extends AbstractValidationRule
{
    public function __construct(protected mixed $reference)
    {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($value !== $this->reference) {
            $violations[] = $this->buildMessage($name, '{name} must be equal to {compare}', ['compare' => json_encode($this->reference)]);
        }
    }
}
