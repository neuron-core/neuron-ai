<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Score;
use NeuronAI\Evaluation\Usage\ModelUsage;

use function array_filter;
use function array_map;
use function array_merge;
use function array_sum;
use function count;
use function max;
use function min;

class EvaluationResults
{
    /**
     * @param array<EvaluatorResult> $results
     * @param array<string> $warnings What the reader should know about how the run was made.
     */
    public function __construct(
        protected readonly array $results,
        protected readonly array $warnings = [],
    ) {
    }

    /**
     * @return array<EvaluatorResult>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Seconds in run() per item that executed it: a cached item spent none, and
     * would lower the average of the items that did.
     */
    public function getAverageRunTime(): ?float
    {
        return $this->average(array_map(fn (EvaluatorResult $result): ?float => $result->getRunTime(), $this->results));
    }

    /**
     * Seconds in evaluate() per item, judges included.
     */
    public function getAverageEvaluationTime(): ?float
    {
        return $this->average(array_map(fn (EvaluatorResult $result): ?float => $result->getEvaluationTime(), $this->results));
    }

    /**
     * What run() consumed, re-runs included: it is what the evaluation spent.
     *
     * @return array<ModelUsage>
     */
    public function getRunUsage(): array
    {
        $records = [];
        foreach ($this->getAttempts() as $result) {
            $records = array_merge($records, $result->getRunUsage());
        }

        return ModelUsage::merge($records);
    }

    /**
     * What the judges consumed, re-runs included.
     *
     * @return array<ModelUsage>
     */
    public function getJudgeUsage(): array
    {
        $records = [];
        foreach ($this->getAttempts() as $result) {
            $records = array_merge($records, $result->getJudgeUsage());
        }

        return ModelUsage::merge($records);
    }

    public function getRerunCount(): int
    {
        return count($this->getAttempts()) - count($this->results);
    }

    /**
     * Every item once, then every re-run.
     *
     * @return array<EvaluatorResult>
     */
    protected function getAttempts(): array
    {
        $attempts = $this->results;
        foreach ($this->results as $result) {
            $attempts = array_merge($attempts, $result->getReruns());
        }

        return $attempts;
    }

    /**
     * @param array<float|null> $values
     */
    protected function average(array $values): ?float
    {
        $measured = array_filter($values, fn (?float $value): bool => $value !== null);

        return $measured === [] ? null : array_sum($measured) / count($measured);
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

    public function getAverageExecutionTime(): float
    {
        if ($this->getTotalCount() === 0) {
            return 0.0;
        }

        // Item timing is independent from the report's wall-clock duration
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
