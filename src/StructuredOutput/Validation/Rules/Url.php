<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Url extends AbstractValidationRule
{
    protected string $message = '{name} must be a valid URL';

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if (\filter_var($value, \FILTER_VALIDATE_URL) === false) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
