<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use Attribute;

use function is_string;
use function preg_match;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Regex extends AbstractValidationRule
{
    public function __construct(protected string $pattern)
    {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if (!is_string($value) || preg_match($this->pattern, $value) !== 1) {
            $violations[] = $this->buildMessage(
                $name,
                '{name} must match the pattern {pattern}',
                ['pattern' => $this->pattern]
            );
        }
    }
}
