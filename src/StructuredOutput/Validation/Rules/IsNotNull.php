<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsNotNull extends AbstractValidationRule
{
    protected string $message = '{name} must not be null';

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($value === null) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
