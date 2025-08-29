<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\Contracts\EvaluationRuleInterface;
use NeuronAI\Evaluation\EvaluationRuleResult;

class StringEndsWithRule implements EvaluationRuleInterface
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
                'Expected suffix to be a string, got ' . \gettype($expected),
                ['actual' => $actual, 'expected' => $expected]
            );
        }

        $result = \str_ends_with($actual, $expected);

        if ($result) {
            return EvaluationRuleResult::pass(1.0, '', ['expected' => $expected, 'actual' => $actual]);
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected response to end with '$expected'",
            ['expected' => $expected, 'actual' => $actual]
        );
    }

    public function getName(): string
    {
        return 'assertStringEndsWith';
    }
}
