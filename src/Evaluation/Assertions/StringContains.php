<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function str_contains;
use function strtolower;

class StringContains extends StringAssertion
{
    public function __construct(protected string $keyword)
    {
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        if (str_contains(strtolower($actual), strtolower($this->keyword))) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected '{$actual}' to contain '{$this->keyword}'",
        );
    }
}
