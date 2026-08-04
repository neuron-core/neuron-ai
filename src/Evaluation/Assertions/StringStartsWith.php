<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function str_starts_with;

class StringStartsWith extends StringAssertion
{
    public function __construct(protected string $prefix)
    {
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        $result = str_starts_with($actual, $this->prefix);

        if ($result) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected response to start with '{$this->prefix}'",
        );
    }
}
