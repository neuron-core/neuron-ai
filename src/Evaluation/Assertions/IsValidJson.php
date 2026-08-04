<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function json_decode;
use function json_last_error;
use function json_last_error_msg;

use const JSON_ERROR_NONE;

class IsValidJson extends StringAssertion
{
    protected function evaluateString(string $actual): AssertionResult
    {
        json_decode($actual);
        $result = json_last_error() === JSON_ERROR_NONE;

        if ($result) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            'Expected valid JSON response: ' . json_last_error_msg(),
        );
    }
}
