<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Score;

use function array_map;
use function end;
use function explode;

class EvaluatorResult
{
    /**
     * @param string $evaluatorClass The evaluator that produced this result. Results of
     *        several evaluators may share one summary (the CLI merges them), so the stamp
     *        is what lets a consumer attribute or regroup them: the index alone is only
     *        unique within one evaluator's dataset.
     * @param array<string, mixed> $input
     * @param array<AssertionFailure> $assertionFailures
     * @param array<Score> $assertionScores
     */
    public function __construct(
        private readonly string $evaluatorClass,
        private readonly int $index,
        private readonly bool $passed,
        private readonly array $input,
        private readonly mixed $output,
        private readonly float $executionTime,
        private readonly int $assertionsPassed,
        private readonly int $assertionsFailed,
        private readonly array $assertionFailures = [],
        private readonly array $assertionScores = [],
        private readonly ?string $error = null,
        private readonly bool $cachedRun = false
    ) {
    }

    public function getEvaluatorClass(): string
    {
        return $this->evaluatorClass;
    }

    public function getShortEvaluatorClass(): string
    {
        $parts = explode('\\', $this->evaluatorClass);
        return end($parts);
    }

    /**
     * Whether run() was skipped and its output served from the evaluation cache.
     * Assertions always execute fresh, so passed/failed reflects the current evaluate().
     */
    public function isCachedRun(): bool
    {
        return $this->cachedRun;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInput(): array
    {
        return $this->input;
    }

    public function getOutput(): mixed
    {
        return $this->output;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function getAssertionsPassed(): int
    {
        return $this->assertionsPassed;
    }

    public function getAssertionsFailed(): int
    {
        return $this->assertionsFailed;
    }

    public function getTotalAssertions(): int
    {
        return $this->assertionsPassed + $this->assertionsFailed;
    }

    /**
     * @return array<AssertionFailure>
     */
    public function getAssertionFailures(): array
    {
        return $this->assertionFailures;
    }

    public function hasAssertionFailures(): bool
    {
        return $this->assertionFailures !== [];
    }

    /**
     * Get all labeled assertion scores
     *
     * @return array<Score>
     */
    public function getScoreRecords(): array
    {
        return $this->assertionScores;
    }

    /**
     * @return array<float>
     */
    public function getAssertionScores(): array
    {
        return array_map(fn (Score $score): float => $score->value, $this->assertionScores);
    }
}
