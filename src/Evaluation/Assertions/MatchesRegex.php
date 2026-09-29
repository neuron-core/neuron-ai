<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Evaluation\AssertionResult;

use function error_get_last;
use function preg_last_error;
use function preg_last_error_msg;
use function preg_match;

use const PREG_INTERNAL_ERROR;

class MatchesRegex extends StringAssertion
{
    public function __construct(protected string $regex)
    {
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        $matched = @preg_match($this->regex, $actual);

        if ($matched === false) {
            throw new InvalidArgumentException("Pattern '{$this->regex}' cannot be matched: {$this->pcreError()}");
        }

        if ($matched === 1) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected '$actual' to match pattern '{$this->regex}'",
        );
    }

    /**
     * A compile error is reported only through the suppressed warning; an
     * engine failure (e.g. backtrack limit) only through preg_last_error().
     */
    protected function pcreError(): string
    {
        return preg_last_error() === PREG_INTERNAL_ERROR
            ? error_get_last()['message'] ?? preg_last_error_msg()
            : preg_last_error_msg();
    }
}
