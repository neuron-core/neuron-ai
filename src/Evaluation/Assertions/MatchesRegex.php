<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function preg_match;

class MatchesRegex extends StringAssertion
{
    public function __construct(protected string $regex)
    {
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        $result = preg_match($this->regex, $actual) === 1;

        if ($result) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected '$actual' to match pattern '{$this->regex}'",
        );
    }
}
