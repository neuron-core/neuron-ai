<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

class StringLengthBetween extends AbstractAssertion
{
    public function __construct(protected int $min, protected int $max)
    {
    }

    public function evaluate(mixed $actual): AssertionResult
    {
        if (!\is_string($actual)) {
            return AssertionResult::fail(
                0.0,
                'Expected actual value to be a string, got ' . \gettype($actual),
            );
        }

        $length = \strlen($actual);
        $result = $length >= $this->min && $length <= $this->max;

        if ($result) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected string length to be between {$this->min} and {$this->max}, got {$length}",
        );
    }
}
