<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\Evaluation\Contracts\AssertionInterface;

use function debug_backtrace;
use function array_sum;
use function count;
use function max;
use function min;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

class RuleExecutor
{
    protected int $passedCount = 0;

    protected int $failedCount = 0;

    /** @var array<AssertionFailure> */
    protected array $failures = [];

    /** @var array<float> */
    protected array $scores = [];

    /**
     * Execute an evaluation rule and track the result
     */
    public function execute(AssertionInterface $rule, mixed $actual): bool
    {
        $result = $rule->evaluate($actual);

        // Track the score regardless of pass/fail
        $this->scores[] = $result->score;

        if ($result->passed) {
            $this->passedCount++;
        } else {
            $this->failedCount++;
            $this->recordFailure($rule, $result);
        }

        return $result->passed;
    }

    /**
     * Get the number of passed assertions
     */
    public function getPassedCount(): int
    {
        return $this->passedCount;
    }

    /**
     * Get the number of failed assertions
     */
    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    /**
     * Get the total number of assertions
     */
    public function getTotalCount(): int
    {
        return $this->passedCount + $this->failedCount;
    }

    /**
     * Get all assertion failures
     *
     * @return array<AssertionFailure>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * Reset all statistics and failures
     */
    public function reset(): void
    {
        $this->passedCount = 0;
        $this->failedCount = 0;
        $this->failures = [];
        $this->scores = [];
    }

    /**
     * Get all assertion scores
     *
     * @return array<float>
     */
    public function getScores(): array
    {
        return $this->scores;
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

    /**
     * Record a failure with proper backtrace information
     */
    protected function recordFailure(AssertionInterface $rule, AssertionResult $result): void
    {
        // Get the calling line from backtrace (skip execute() and recordFailure())
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $lineNumber = $backtrace[3]['line'] ?? 0;
        $evaluatorClass = $backtrace[3]['class'] ?? 'Unknown';

        $this->failures[] = new AssertionFailure(
            $evaluatorClass,
            $rule->getName(),
            $result->message !== '' ? $result->message : 'Evaluation rule failed',
            $lineNumber,
            $result->context
        );
    }
}
