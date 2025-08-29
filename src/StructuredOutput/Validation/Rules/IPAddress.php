<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class IPAddress extends AbstractValidationRule
{
    protected string $message = '{name} must be a valid IP address';

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if (\filter_var($value, \FILTER_VALIDATE_IP) === false) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
