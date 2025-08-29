<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\EvaluationRuleResult;

class StringLengthBetweenRule extends AbstractRule
{
    public function __construct(protected int $min, protected int $max)
    {
    }

    public function evaluate(mixed $actual): EvaluationRuleResult
    {
        if (!\is_string($actual)) {
            return EvaluationRuleResult::fail(
                0.0,
                'Expected actual value to be a string, got ' . \gettype($actual),
            );
        }

        $length = \strlen($actual);
        $result = $length >= $this->min && $length <= $this->max;

        if ($result) {
            return EvaluationRuleResult::pass(1.0);
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected string length to be between {$this->min} and {$this->max}, got {$length}",
        );
    }
}
