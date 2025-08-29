<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\Contracts\EvaluationRuleInterface;
use NeuronAI\Evaluation\EvaluationRuleResult;

class StringContainsAnyRule implements EvaluationRuleInterface
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

        foreach ($expected as $keyword) {
            if (!\is_string($keyword)) {
                continue;
            }

            if (\str_contains($lowerHaystack, \strtolower($keyword))) {
                return EvaluationRuleResult::pass(
                    1.0,
                    '',
                    ['keywords' => $expected, 'haystack' => $actual, 'matched' => $keyword]
                );
            }
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected '$actual' to contain any of: " . \implode(', ', $expected),
            ['keywords' => $expected, 'haystack' => $actual]
        );
    }

    public function getName(): string
    {
        return 'assertStringContainsAny';
    }
}
