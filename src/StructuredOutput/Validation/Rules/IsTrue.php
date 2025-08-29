<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IsTrue extends AbstractValidationRule
{
    protected string $message = '{name} must be true';

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($value !== true) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
