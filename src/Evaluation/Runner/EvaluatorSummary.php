<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\AssertionFailure;

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
        return \count($this->results);
    }

    public function getPassedCount(): int
    {
        return \count(\array_filter($this->results, fn (EvaluatorResult $result): bool => $result->isPassed()));
    }

    public function getFailedCount(): int
    {
        return $this->getTotalCount() - $this->getPassedCount();
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

        return $this->totalExecutionTime / $this->getTotalCount();
    }

    /**
     * @return array<EvaluatorResult>
     */
    public function getFailedResults(): array
    {
        return \array_filter($this->results, fn (EvaluatorResult $result): bool => !$result->isPassed());
    }

    public function hasFailures(): bool
    {
        return $this->getFailedCount() > 0;
    }

    public function getTotalAssertionsPassed(): int
    {
        return \array_sum(\array_map(fn (EvaluatorResult $result): int => $result->getAssertionsPassed(), $this->results));
    }

    public function getTotalAssertionsFailed(): int
    {
        return \array_sum(\array_map(fn (EvaluatorResult $result): int => $result->getAssertionsFailed(), $this->results));
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
            $failures = \array_merge($failures, $result->getAssertionFailures());
        }
        return $failures;
    }

    /**
     * Get assertion failures grouped by evaluator class
     *
     * @return array<string, AssertionFailure[]>
     */
    public function getAssertionFailuresByClass(): array
    {
        $groupedFailures = [];
        foreach ($this->getAllAssertionFailures() as $failure) {
            $class = $failure->getEvaluatorClass();
            if (!isset($groupedFailures[$class])) {
                $groupedFailures[$class] = [];
            }
            $groupedFailures[$class][] = $failure;
        }
        return $groupedFailures;
    }

    /**
     * Get assertion failures grouped by evaluator class and line
     *
     * @return array<string, AssertionFailure[]>
     */
    public function getAssertionFailuresByLocation(): array
    {
        $groupedFailures = [];
        foreach ($this->getAllAssertionFailures() as $failure) {
            $key = $failure->getShortEvaluatorClass() . ':' . $failure->getLineNumber();
            if (!isset($groupedFailures[$key])) {
                $groupedFailures[$key] = [];
            }
            $groupedFailures[$key][] = $failure;
        }
        return $groupedFailures;
    }
}
