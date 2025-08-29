<?php

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\EvaluationRuleResult;

class StringContains extends AbstractRule
{
    public function __construct(protected string $keyword)
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

        if (\str_contains(\strtolower($actual), \strtolower($this->keyword))) {
            return EvaluationRuleResult::pass(1.0);
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected '{$actual}' to contain '{$this->keyword}'",
        );
    }
}
