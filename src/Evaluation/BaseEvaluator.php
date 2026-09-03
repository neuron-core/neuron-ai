<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\Evaluation\Contracts\AssertionInterface;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;

abstract class BaseEvaluator implements EvaluatorInterface
{
    protected RuleExecutor $ruleExecutor;

    public function __construct()
    {
        $this->ruleExecutor = new RuleExecutor();
    }

    public function namespace(): ?string
    {
        return null;
    }

    /**
     * Override to initialize judge agents and other resources before evaluation starts.
     */
    public function setUp(): void
    {
    }

    abstract public function getDataset(): DatasetInterface;

    /**
     * Run the agent logic being tested.
     *
     * @param array<string, mixed> $datasetItem
     */
    abstract public function run(array $datasetItem): mixed;

    /**
     * Assert the run() output against the reference dataset item.
     *
     * @param array<string, mixed> $datasetItem
     */
    abstract public function evaluate(mixed $output, array $datasetItem): void;

    /**
     * Sources beyond the evaluator itself (agent classes, prompt files) that
     * participate in the run() cache key — content changes invalidate cached runs.
     *
     * @return array<string> Class-strings or file paths
     */
    public function cacheDependencies(): array
    {
        return [];
    }

    /**
     * @internal Used by EvaluatorRunner
     */
    final public function performEvaluation(mixed $output, array $datasetItem): AssertionOutcomes
    {
        // Reset state before each evaluation to prevent leakage between dataset items
        $this->ruleExecutor->reset();

        $this->evaluate($output, $datasetItem);

        return $this->ruleExecutor->snapshot();
    }

    /**
     * The optional label names the metric the score is recorded under
     * (defaults to the assertion's getName()).
     */
    protected function assert(AssertionInterface $rule, mixed $actual, ?string $label = null): bool
    {
        return $this->ruleExecutor->execute($rule, $actual, $label);
    }
}
