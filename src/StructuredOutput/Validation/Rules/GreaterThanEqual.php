<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use Attribute;

use function is_null;
use function json_encode;

#[Attribute(Attribute::TARGET_PROPERTY)]
class GreaterThanEqual extends AbstractValidationRule
{
    public function __construct(protected mixed $reference)
    {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        // Presence is the type's to decide: a comparison applies only when there is a value
        if (is_null($value)) {
            return;
        }

        if (is_null($this->reference) || $value < $this->reference) {
            $violations[] = $this->buildMessage($name, '{name} must be greater than or equal to {compare}', ['compare' => json_encode($this->reference)]);
        }
    }
}
