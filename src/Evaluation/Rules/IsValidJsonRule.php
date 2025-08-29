<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\Contracts\EvaluationRuleInterface;
use NeuronAI\Evaluation\EvaluationRuleResult;

class IsValidJsonRule implements EvaluationRuleInterface
{
    public function evaluate(mixed $actual, mixed $expected = null, array $context = []): EvaluationRuleResult
    {
        if (!\is_string($actual)) {
            return EvaluationRuleResult::fail(
                0.0,
                'Expected actual value to be a string, got ' . \gettype($actual),
                ['actual' => $actual]
            );
        }

        \json_decode($actual);
        $result = \json_last_error() === \JSON_ERROR_NONE;

        if ($result) {
            return EvaluationRuleResult::pass(1.0, '', ['response' => $actual]);
        }

        return EvaluationRuleResult::fail(
            0.0,
            'Expected valid JSON response: ' . \json_last_error_msg(),
            ['response' => $actual, 'json_error' => \json_last_error_msg()]
        );
    }

    public function getName(): string
    {
        return 'assertIsValidJson';
    }
}
