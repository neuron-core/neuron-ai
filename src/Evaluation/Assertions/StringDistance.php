<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Evaluation\AssertionResult;

use function levenshtein;

class StringDistance extends StringAssertion
{
    public function __construct(
        protected string $reference,
        protected float $threshold = 0.5,
        protected int $maxDistance = 50
    ) {
        $this->validateThreshold($threshold);

        if ($maxDistance < 0) {
            throw new InvalidArgumentException('Max distance must not be negative.');
        }
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        $distance = levenshtein($actual, $this->reference);

        if ($distance <= $this->maxDistance) {
            // A zero max distance admits only identical strings
            $score = $this->maxDistance === 0 ? 1.0 : 1.0 - ($distance / $this->maxDistance);

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
