<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function str_ends_with;

class StringEndsWith extends StringAssertion
{
    public function __construct(protected string $suffix)
    {
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        $result = str_ends_with($actual, $this->suffix);

        if ($result) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected response to end with '{$this->suffix}'",
        );
    }
}
