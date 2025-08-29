<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\Evaluation\Contracts\AssertionInterface;

class RuleExecutor
{
    private int $passedCount = 0;

    private int $failedCount = 0;

    /** @var array<AssertionFailure> */
    private array $failures = [];

    /**
     * Execute an evaluation rule and track the result
     */
    public function execute(AssertionInterface $rule, mixed $actual): bool
    {
        $result = $rule->evaluate($actual);

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
    }

    /**
     * Record a failure with proper backtrace information
     */
    private function recordFailure(AssertionInterface $rule, AssertionResult $result): void
    {
        // Get the calling line from backtrace (skip execute() and recordFailure())
        $backtrace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 4);
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
