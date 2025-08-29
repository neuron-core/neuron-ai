<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class NotBlank extends AbstractValidationRule
{
    protected string $message = '{name} cannot be blank';

    public function __construct(
        protected bool $allowNull = false
    ) {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($this->allowNull && $value === null) {
            return;
        }

        if (false === $value || (empty($value) && '0' != $value)) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
