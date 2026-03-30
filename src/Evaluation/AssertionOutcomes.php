<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use function array_sum;
use function count;
use function max;
use function min;

/**
 * Immutable value object representing assertion outcomes from an evaluation.
 */
class AssertionOutcomes
{
    /**
     * @param int $passedCount Number of assertions that passed
     * @param int $failedCount Number of assertions that failed
     * @param array<AssertionFailure> $failures List of assertion failures
     * @param array<float> $scores List of assertion scores
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

    /**
     * Get the total number of assertions
     */
    public function getTotalCount(): int
    {
        return $this->passedCount + $this->failedCount;
    }

    /**
     * Get the average assertion score
     */
    public function getAverageScore(): float
    {
        if ($this->scores === []) {
            return 0.0;
        }
        return array_sum($this->scores) / count($this->scores);
    }

    /**
     * Get the minimum assertion score
     */
    public function getMinScore(): float
    {
        if ($this->scores === []) {
            return 0.0;
        }
        return min($this->scores);
    }

    /**
     * Get the maximum assertion score
     */
    public function getMaxScore(): float
    {
        if ($this->scores === []) {
            return 0.0;
        }
        return max($this->scores);
    }
}
