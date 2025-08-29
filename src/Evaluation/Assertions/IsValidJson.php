<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

class IsValidJson extends AbstractAssertion
{
    public function evaluate(mixed $actual): AssertionResult
    {
        if (!\is_string($actual)) {
            return AssertionResult::fail(
                0.0,
                'Expected actual value to be a string, got ' . \gettype($actual),
                ['actual' => $actual]
            );
        }

        \json_decode($actual);
        $result = \json_last_error() === \JSON_ERROR_NONE;

        if ($result) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            'Expected valid JSON response: ' . \json_last_error_msg(),
        );
    }
}
