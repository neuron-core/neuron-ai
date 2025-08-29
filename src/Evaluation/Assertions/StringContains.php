<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

class StringContains extends AbstractAssertion
{
    public function __construct(protected string $keyword)
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

        if (\str_contains(\strtolower($actual), \strtolower($this->keyword))) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected '{$actual}' to contain '{$this->keyword}'",
        );
    }
}
