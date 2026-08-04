<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function levenshtein;

class StringDistance extends StringAssertion
{
    public function __construct(
        protected string $reference,
        protected float $threshold = 0.5,
        protected int $maxDistance = 50
    ) {
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        $distance = levenshtein($actual, $this->reference);

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
