<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

class StringDistance extends AbstractAssertion
{
    public function __construct(
        protected string $reference,
        protected float $threshold = 0.5,
        protected int $maxDistance = 50
    ) {
    }

    public function evaluate(mixed $actual): AssertionResult
    {
        if (!\is_string($actual)) {
            return AssertionResult::fail(
                0.0,
                'Expected actual value to be a string, got ' . \gettype($actual),
            );
        }

        $distance = \levenshtein($actual, $this->reference);

        if ($distance <= $this->maxDistance) {
            $score = 1.0 - ($distance / $this->maxDistance);

            if ($score < $this->threshold) {
                return AssertionResult::fail(
                    $score,
                    "Expected '{$actual}' to be similar to '{$this->reference}' (distance: {$distance}, threshold: {$this->threshold}, max_accepted: {$this->maxDistance})"
                );
            }

            return AssertionResult::pass($score);
        }

        return AssertionResult::fail(
            0.0,
            "Expected '{$actual}' to be similar to '{$this->reference}' (distance: {$distance}, max_accepted: {$this->maxDistance})",
        );
    }
}
