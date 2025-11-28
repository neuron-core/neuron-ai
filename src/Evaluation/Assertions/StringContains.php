<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function gettype;
use function is_string;
use function str_contains;
use function strtolower;

class StringContains extends AbstractAssertion
{
    public function __construct(protected string $keyword)
    {
    }

    public function evaluate(mixed $actual): AssertionResult
    {
        if (!is_string($actual)) {
            return AssertionResult::fail(
                0.0,
                'Expected actual value to be a string, got ' . gettype($actual),
            );
        }

        if (str_contains(strtolower($actual), strtolower($this->keyword))) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected '{$actual}' to contain '{$this->keyword}'",
        );
    }
}
