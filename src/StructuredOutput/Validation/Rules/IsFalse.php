<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsFalse extends AbstractValidationRule
{
    protected string $message = '{name} must be false';

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($value !== false) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
