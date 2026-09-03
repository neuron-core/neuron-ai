<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Score;

use function array_filter;
use function array_map;
use function array_merge;
use function array_sum;
use function count;
use function max;
use function min;

class EvaluatorSummary
{
    /**
     * @param array<EvaluatorResult> $results
     */
    public function __construct(
        private readonly array $results,
        private readonly float $totalExecutionTime
    ) {
    }

    /**
     * @return array<EvaluatorResult>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getTotalCount(): int
    {
        return count($this->results);
    }

    public function getPassedCount(): int
    {
        return count(array_filter($this->results, fn (EvaluatorResult $result): bool => $result->isPassed()));
    }

    public function getFailedCount(): int
    {
        return $this->getTotalCount() - $this->getPassedCount();
    }

    public function getCachedRunCount(): int
    {
        return count(array_filter($this->results, fn (EvaluatorResult $result): bool => $result->isCachedRun()));
    }

    public function getSuccessRate(): float
    {
        if ($this->getTotalCount() === 0) {
            return 0.0;
        }

        return $this->getPassedCount() / $this->getTotalCount();
    }

    public function getTotalExecutionTime(): float
    {
        return $this->totalExecutionTime;
    }

    public function getAverageExecutionTime(): float
    {
        if ($this->getTotalCount() === 0) {
            return 0.0;
        }

        // Average of individual item timings: under parallel execution the
        // wall-clock total no longer equals the sum of per-item times
        $itemTimes = array_sum(array_map(fn (EvaluatorResult $result): float => $result->getExecutionTime(), $this->results));

        return $itemTimes / $this->getTotalCount();
    }

    /**
     * @return array<EvaluatorResult>
     */
    public function getFailedResults(): array
    {
        return array_filter($this->results, fn (EvaluatorResult $result): bool => !$result->isPassed());
    }

    /**
     * Results grouped by the evaluator that produced them: the seam for a consumer
     * handed a merged summary (the CLI outputs one for every evaluator it ran).
     *
     * @return array<string, EvaluatorResult[]>
     */
    public function getResultsByEvaluatorClass(): array
    {
        $grouped = [];
        foreach ($this->results as $result) {
            $grouped[$result->getEvaluatorClass()][] = $result;
        }
        return $grouped;
    }

    public function hasFailures(): bool
    {
        return $this->getFailedCount() > 0;
    }

    public function getTotalAssertionsPassed(): int
    {
        return array_sum(array_map(fn (EvaluatorResult $result): int => $result->getAssertionsPassed(), $this->results));
    }

    public function getTotalAssertionsFailed(): int
    {
        return array_sum(array_map(fn (EvaluatorResult $result): int => $result->getAssertionsFailed(), $this->results));
    }

    public function getTotalAssertions(): int
    {
        return $this->getTotalAssertionsPassed() + $this->getTotalAssertionsFailed();
    }

    public function getAssertionSuccessRate(): float
    {
        $total = $this->getTotalAssertions();
        if ($total === 0) {
            return 0.0;
        }

        return $this->getTotalAssertionsPassed() / $total;
    }

    /**
     * @return array<AssertionFailure>
     */
    public function getAllAssertionFailures(): array
    {
        $failures = [];
        foreach ($this->results as $result) {
            $failures = array_merge($failures, $result->getAssertionFailures());
        }
        return $failures;
    }

    /**
     * @return array<string, AssertionFailure[]>
     */
    public function getAssertionFailuresByClass(): array
    {
        $groupedFailures = [];
        foreach ($this->getAllAssertionFailures() as $failure) {
            $class = $failure->getEvaluatorClass();
            $groupedFailures[$class] ??= [];
            $groupedFailures[$class][] = $failure;
        }
        return $groupedFailures;
    }

    /**
     * @return array<string, AssertionFailure[]> keyed by "EvaluatorClass:line"
     */
    public function getAssertionFailuresByLocation(): array
    {
        $groupedFailures = [];
        foreach ($this->getAllAssertionFailures() as $failure) {
            $key = $failure->getShortEvaluatorClass() . ':' . $failure->getLineNumber();
            $groupedFailures[$key] ??= [];
            $groupedFailures[$key][] = $failure;
        }
        return $groupedFailures;
    }

    /**
     * @return array<Score>
     */
    public function getAllScoreRecords(): array
    {
        $scores = [];
        foreach ($this->results as $result) {
            $scores = array_merge($scores, $result->getScoreRecords());
        }
        return $scores;
    }

    /**
     * @return array<string, array<Score>>
     */
    public function getScoresByLabel(): array
    {
        $grouped = [];
        foreach ($this->getAllScoreRecords() as $score) {
            $grouped[$score->label][] = $score;
        }
        return $grouped;
    }

    /**
     * @return array<string, array{average: float, min: float, max: float, count: int}>
     */
    public function getScoreStatisticsByLabel(): array
    {
        $statistics = [];
        foreach ($this->getScoresByLabel() as $label => $scores) {
            $values = array_map(fn (Score $score): float => $score->value, $scores);
            $statistics[$label] = [
                'average' => array_sum($values) / count($values),
                'min' => min($values),
                'max' => max($values),
                'count' => count($values),
            ];
        }
        return $statistics;
    }

    /**
     * @return array<float>
     */
    public function getAllAssertionScores(): array
    {
        return array_map(fn (Score $score): float => $score->value, $this->getAllScoreRecords());
    }

    public function getAverageAssertionScore(): float
    {
        $scores = $this->getAllAssertionScores();
        if ($scores === []) {
            return 0.0;
        }
        return array_sum($scores) / count($scores);
    }

    public function getMinAssertionScore(): float
    {
        $scores = $this->getAllAssertionScores();
        if ($scores === []) {
            return 0.0;
        }
        return min($scores);
    }

    public function getMaxAssertionScore(): float
    {
        $scores = $this->getAllAssertionScores();
        if ($scores === []) {
            return 0.0;
        }
        return max($scores);
    }
}
