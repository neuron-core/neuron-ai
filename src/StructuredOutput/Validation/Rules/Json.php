<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use NeuronAI\StructuredOutput\StructuredOutputException;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Json extends AbstractValidationRule
{
    protected string $message = '{name} must be a valid JSON string';

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            throw new StructuredOutputException('Cannot validate a non-scalar value.');
        }

        $value = (string) $value;

        try {
            \json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
