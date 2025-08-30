<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use NeuronAI\StructuredOutput\StructuredOutputException;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class WordsCount extends AbstractValidationRule
{
    public function __construct(
        protected ?int $exactly = null,
        protected ?int $min = null,
        protected ?int $max = null,
    ) {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if (null !== $this->exactly && null === $this->min && null === $this->max) {
            $this->min = $this->max = $this->exactly;
        }

        if (null === $this->min && null === $this->max) {
            throw new StructuredOutputException('Either option "min" or "max" must be given for validation rule "WordsCount"');
        }

        if (\is_null($value) && ($this->min > 0 || $this->exactly > 0)) {
            $violations[] = $this->buildMessage($name, '{name} cannot be empty');
            return;
        }

        if (!\is_string($value) && !$value instanceof \Stringable) {
            $violations[] = $this->buildMessage($name, '{name} must be a string or a stringable object');
            return;
        }

        $results = \preg_split('/[ \-\r\n]+/', (string) $value, -1, \PREG_SPLIT_NO_EMPTY);
        $length = \count($results);

        if (null !== $this->max && $length > $this->max) {
            $shouldExact = $this->min == $this->max;

            if ($shouldExact) {
                $violations[] = $this->buildMessage($name, '{name} must have exactly {exact} words', ['exact' => $this->min]);
            } else {
                $violations[] = $this->buildMessage($name, '{name} is too long. It must be at most {max} words', ['max' => $this->max]);
            }
        }

        if (null !== $this->min && $length < $this->min) {
            $shouldExact = $this->min == $this->max;

            if ($shouldExact) {
                $violations[] = $this->buildMessage($name, '{name} must have exactly {exact} words long', ['exact' => $this->min]);
            } else {
                $violations[] = $this->buildMessage($name, '{name} is too short. It must be at least {min} words', ['min' => $this->min]);
            }
        }
    }
}
