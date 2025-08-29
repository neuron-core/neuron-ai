<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\EvaluationRuleResult;

class StringDistance extends AbstractRule
{
    public function __construct(protected string $reference, protected int $maxDistance = 50)
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

        $distance = \levenshtein($actual, $this->reference);

        if ($distance <= $this->maxDistance) {
            $score = 1.0 - ($distance / $this->maxDistance);
            return EvaluationRuleResult::pass($score);
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected '{$actual}' to be similar to '{$this->reference}' (distance: {$distance}, max_accepted: {$this->maxDistance})",
        );
    }
}
