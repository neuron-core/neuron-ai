<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Score;
use NeuronAI\Evaluation\Usage\ModelUsage;

use function array_filter;
use function array_map;
use function count;
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
     * @param float|null $runTime Seconds in run(), null when it did not execute (a cached run).
     * @param float|null $evaluationTime Seconds in evaluate(), judges included, null when it did not execute.
     * @param array<ModelUsage> $runUsage What run() consumed: empty when nothing was measured.
     * @param array<ModelUsage> $judgeUsage What the judges of evaluate() consumed.
     * @param array<EvaluatorResult> $reruns Later attempts at a failed item. They never change its verdict.
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
        private readonly bool $cachedRun = false,
        private readonly ?float $runTime = null,
        private readonly ?float $evaluationTime = null,
        private readonly array $runUsage = [],
        private readonly array $judgeUsage = [],
        private readonly array $reruns = [],
    ) {
    }

    /**
     * @param array<EvaluatorResult> $reruns
     */
    public function withReruns(array $reruns): self
    {
        return $this->copy(['reruns' => $reruns]);
    }

    /**
     * The same result with another output, for an output that cannot cross the fork boundary.
     */
    public function withOutput(mixed $output): self
    {
        return $this->copy(['output' => $output]);
    }

    /**
     * @param array<string, mixed> $changes By constructor parameter name.
     */
    protected function copy(array $changes): self
    {
        return new self(...[
            'evaluatorClass' => $this->evaluatorClass,
            'index' => $this->index,
            'passed' => $this->passed,
            'input' => $this->input,
            'output' => $this->output,
            'executionTime' => $this->executionTime,
            'assertionsPassed' => $this->assertionsPassed,
            'assertionsFailed' => $this->assertionsFailed,
            'assertionFailures' => $this->assertionFailures,
            'assertionScores' => $this->assertionScores,
            'error' => $this->error,
            'cachedRun' => $this->cachedRun,
            'runTime' => $this->runTime,
            'evaluationTime' => $this->evaluationTime,
            'runUsage' => $this->runUsage,
            'judgeUsage' => $this->judgeUsage,
            'reruns' => $this->reruns,
            ...$changes,
        ]);
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

    public function getRunTime(): ?float
    {
        return $this->runTime;
    }

    public function getEvaluationTime(): ?float
    {
        return $this->evaluationTime;
    }

    /**
     * @return array<ModelUsage>
     */
    public function getRunUsage(): array
    {
        return $this->runUsage;
    }

    /**
     * @return array<ModelUsage>
     */
    public function getJudgeUsage(): array
    {
        return $this->judgeUsage;
    }

    /**
     * @return array<EvaluatorResult>
     */
    public function getReruns(): array
    {
        return $this->reruns;
    }

    public function getRerunsPassed(): int
    {
        return count(array_filter($this->reruns, fn (EvaluatorResult $rerun): bool => $rerun->isPassed()));
    }

    /**
     * Re-runs that reached no verdict: they say nothing about the failure.
     */
    public function getRerunsErrored(): int
    {
        return count(array_filter($this->reruns, fn (EvaluatorResult $rerun): bool => $rerun->hasError()));
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
