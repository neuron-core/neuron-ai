<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

class StringStartsWith extends AbstractAssertion
{
    public function __construct(protected string $prefix)
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

        $result = \str_starts_with($actual, $this->prefix);

        if ($result) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected response to start with '{$this->prefix}'",
        );
    }
}
