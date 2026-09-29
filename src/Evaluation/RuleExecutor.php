<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\Evaluation\Contracts\AssertionInterface;

class RuleExecutor
{
    protected int $passedCount = 0;

    protected int $failedCount = 0;

    /** @var array<AssertionFailure> */
    protected array $failures = [];

    /** @var array<Score> */
    protected array $scores = [];

    /**
     * @param string $evaluatorClass The evaluator failures are attributed to.
     */
    public function __construct(protected readonly string $evaluatorClass)
    {
    }

    /**
     * Execute an evaluation rule and track the result
     *
     * @param int $line Where the evaluator asserted, reported with a failure.
     */
    public function execute(AssertionInterface $rule, mixed $actual, int $line, ?string $label = null): bool
    {
        $result = $rule->evaluate($actual);

        // Track the score regardless of pass/fail
        $this->scores[] = new Score($label ?? $rule->getName(), $result->score, $result->passed);

        if ($result->passed) {
            $this->passedCount++;
        } else {
            $this->failedCount++;
            $this->recordFailure($rule, $result, $line);
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

    protected function recordFailure(AssertionInterface $rule, AssertionResult $result, int $line): void
    {
        $this->failures[] = new AssertionFailure(
            $this->evaluatorClass,
            $rule->getName(),
            $result->message !== '' ? $result->message : 'Evaluation rule failed',
            $line,
            $result->context
        );
    }
}
