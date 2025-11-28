<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function gettype;
use function is_string;
use function str_ends_with;

class StringEndsWith extends AbstractAssertion
{
    public function __construct(protected string $suffix)
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
