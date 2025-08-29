<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\EvaluationRuleResult;

class StringEndsWith extends AbstractRule
{
    public function __construct(protected string $suffix)
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

        $result = \str_ends_with($actual, $this->suffix);

        if ($result) {
            return EvaluationRuleResult::pass(1.0);
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected response to end with '{$this->suffix}'",
        );
    }
}
