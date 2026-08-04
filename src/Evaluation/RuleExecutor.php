<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\Evaluation\Contracts\AssertionInterface;

use function debug_backtrace;
use function array_map;
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

    /** @var array<Score> */
    protected array $scores = [];

    /**
     * Execute an evaluation rule and track the result
     */
    public function execute(AssertionInterface $rule, mixed $actual, ?string $label = null): bool
    {
        $result = $rule->evaluate($actual);

        // Track the score regardless of pass/fail
        $this->scores[] = new Score($label ?? $rule->getName(), $result->score, $result->passed);

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
     * Get all assertion score values
     *
     * @return array<float>
     */
    public function getScores(): array
    {
        return array_map(fn (Score $score): float => $score->value, $this->scores);
    }

    /**
     * Get all labeled assertion scores
     *
     * @return array<Score>
     */
    public function getScoreRecords(): array
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
        return array_sum($this->getScores()) / count($this->scores);
    }

    /**
     * Get the minimum assertion score
     */
    public function getMinScore(): float
    {
        if ($this->scores === []) {
            return 0.0;
        }
        return min($this->getScores());
    }

    /**
     * Get the maximum assertion score
     */
    public function getMaxScore(): float
    {
        if ($this->scores === []) {
            return 0.0;
        }
        return max($this->getScores());
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
