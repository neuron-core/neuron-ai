<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function mb_strlen;

class StringLengthBetween extends StringAssertion
{
    public function __construct(protected int $min, protected int $max)
    {
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        $length = mb_strlen($actual);
        $result = $length >= $this->min && $length <= $this->max;

        if ($result) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected string length to be between {$this->min} and {$this->max}, got {$length}",
        );
    }
}
