<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\Contracts\EvaluationRuleInterface;
use NeuronAI\Evaluation\EvaluationRuleResult;

class StringContainsAnyRule extends AbstractRule
{
    public function __construct(protected array $keywords)
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

        $lowerHaystack = \strtolower($actual);

        foreach ($this->keywords as $keyword) {
            if (!\is_string($keyword)) {
                continue;
            }

            if (\str_contains($lowerHaystack, \strtolower($keyword))) {
                return EvaluationRuleResult::pass(1.0);
            }
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected '$actual' to contain any of: " . \implode(', ', $this->keywords),
        );
    }
}
