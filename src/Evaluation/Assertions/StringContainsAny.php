<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

class StringContainsAny extends AbstractAssertion
{
    public function __construct(protected array $keywords)
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

        $lowerHaystack = \strtolower($actual);

        foreach ($this->keywords as $keyword) {
            if (!\is_string($keyword)) {
                continue;
            }

            if (\str_contains($lowerHaystack, \strtolower($keyword))) {
                return AssertionResult::pass(1.0);
            }
        }

        return AssertionResult::fail(
            0.0,
            "Expected '{$actual}' to contain any of: " . \implode(', ', $this->keywords),
        );
    }
}
