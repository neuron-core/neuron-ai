<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\Contracts\EvaluationRuleInterface;
use NeuronAI\Evaluation\EvaluationRuleResult;

class MatchesRegexRule implements EvaluationRuleInterface
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

        if (!\is_string($expected)) {
            return EvaluationRuleResult::fail(
                0.0,
                'Expected pattern to be a string, got ' . \gettype($expected),
                ['actual' => $actual, 'expected' => $expected]
            );
        }

        $result = \preg_match($expected, $actual) === 1;

        if ($result) {
            return EvaluationRuleResult::pass(1.0, '', ['pattern' => $expected, 'subject' => $actual]);
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected '$actual' to match pattern '$expected'",
            ['pattern' => $expected, 'subject' => $actual]
        );
    }

    public function getName(): string
    {
        return 'assertMatchesRegex';
    }
}
