<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\EvaluationRuleResult;

class StringStartsWithRule extends AbstractRule
{
    public function __construct(protected string $prefix)
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

        $result = \str_starts_with($actual, $this->prefix);

        if ($result) {
            return EvaluationRuleResult::pass(1.0);
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected response to start with '{$this->prefix}'",
        );
    }
}
