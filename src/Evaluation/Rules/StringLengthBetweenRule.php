<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\Contracts\EvaluationRuleInterface;
use NeuronAI\Evaluation\EvaluationRuleResult;

class StringLengthBetweenRule implements EvaluationRuleInterface
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

        if (!\is_array($expected) || \count($expected) !== 2) {
            return EvaluationRuleResult::fail(
                0.0,
                'Expected array with [min, max] values, got ' . \gettype($expected),
                ['actual' => $actual, 'expected' => $expected]
            );
        }

        [$min, $max] = $expected;

        if (!\is_int($min) || !\is_int($max)) {
            return EvaluationRuleResult::fail(
                0.0,
                'Expected min and max to be integers',
                ['actual' => $actual, 'expected' => $expected]
            );
        }

        $length = \strlen($actual);
        $result = $length >= $min && $length <= $max;

        if ($result) {
            return EvaluationRuleResult::pass(
                1.0,
                '',
                ['string' => $actual, 'min' => $min, 'max' => $max, 'actual_length' => $length]
            );
        }

        return EvaluationRuleResult::fail(
            0.0,
            "Expected string length to be between $min and $max, got $length",
            ['string' => $actual, 'min' => $min, 'max' => $max, 'actual_length' => $length]
        );
    }

    public function getName(): string
    {
        return 'assertStringLengthBetween';
    }
}
