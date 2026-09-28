<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use Attribute;

use function is_null;
use function json_encode;

#[Attribute(Attribute::TARGET_PROPERTY)]
class GreaterThan extends AbstractValidationRule
{
    public function __construct(protected mixed $reference)
    {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if (is_null($this->reference) || $value <= $this->reference) {
            $violations[] = $this->buildMessage($name, '{name} must be greater than {compare}', ['compare' => json_encode($this->reference)]);
        }
    }
}
