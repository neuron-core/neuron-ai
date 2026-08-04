<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\Evaluation\Contracts\AssertionInterface;

use function debug_backtrace;

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
     * The accumulated outcomes as an immutable value object.
     */
    public function snapshot(): AssertionOutcomes
    {
        return new AssertionOutcomes(
            $this->passedCount,
            $this->failedCount,
            $this->failures,
            $this->scores,
        );
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
