<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

/**
 * Immutable value object representing assertion outcomes from an evaluation.
 */
class AssertionOutcomes
{
    /**
     * @param int $passedCount Number of assertions that passed
     * @param int $failedCount Number of assertions that failed
     * @param array<AssertionFailure> $failures List of assertion failures
     * @param array<Score> $scores List of labeled assertion scores
     */
    public function __construct(
        public readonly int $passedCount,
        public readonly int $failedCount,
        public readonly array $failures = [],
        public readonly array $scores = [],
    ) {
    }

    /**
     * Check if all assertions passed (no failures)
     */
    public function isPassed(): bool
    {
        return $this->failedCount === 0;
    }
}
