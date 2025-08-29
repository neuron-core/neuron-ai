<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\Contracts\EvaluationRuleInterface;
use NeuronAI\Evaluation\EvaluationRuleResult;

class StringContainsAllRule implements EvaluationRuleInterface
{
    public function evaluate(mixed $actual, mixed $expected = null, array $context = []): EvaluationRuleResult
    {
        if (!\is_string($actual)) {
            return EvaluationRuleResult::fail(
                0.0,
                'Expected actual value to be a string, got ' . \gettype($actual),
                ['actual' => $actual, 'expected' => $expected]
            );
        }

        if (!\is_array($expected)) {
            return EvaluationRuleResult::fail(
                0.0,
                'Expected keywords to be an array, got ' . \gettype($expected),
                ['actual' => $actual, 'expected' => $expected]
            );
        }

        $lowerHaystack = \strtolower($actual);
        $missing = [];

        foreach ($expected as $keyword) {
            if (!\is_string($keyword)) {
                continue;
            }

            if (!\str_contains($lowerHaystack, \strtolower($keyword))) {
                $missing[] = $keyword;
            }
        }

        if (empty($missing)) {
            return EvaluationRuleResult::pass(
                1.0,
                '',
                ['keywords' => $expected, 'haystack' => $actual]
            );
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected '$actual' to contain all keywords. Missing: " . \implode(', ', $missing),
            ['keywords' => $expected, 'haystack' => $actual, 'missing' => $missing]
        );
    }

    public function getName(): string
    {
        return 'assertStringContainsAll';
    }
}
