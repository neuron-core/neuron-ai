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

    /**
     * Set up the method called before evaluation starts.
     * Override this to initialize judge agents and other resources
     */
    public function setUp(): void
    {
        // Default empty implementation - developers override as needed
    }

    /**
     * Get the dataset for this evaluator
     */
    abstract public function getDataset(): DatasetInterface;

    /**
     * Run the agent logic being tested
     *
     * @param array<string, mixed> $datasetItem Current item from the dataset
     * @return mixed Output from the application logic
     */
    abstract public function run(array $datasetItem): mixed;

    /**
     * Evaluate the output against expected results, with assertions.
     * Developers implement this method to define their assertions.
     *
     * @param mixed $output Output from the run() method
     * @param array<string, mixed> $datasetItem Reference dataset item for comparison
     */
    abstract public function evaluate(mixed $output, array $datasetItem): void;

    /**
     * Perform evaluation and return assertion outcomes.
     * This is the wrapper method called by the runner.
     *
     * @internal Used by EvaluatorRunner
     */
    final public function performEvaluation(mixed $output, array $datasetItem): AssertionOutcomes
    {
        // Reset state before each evaluation to prevent leakage between dataset items
        $this->ruleExecutor->reset();

        // Call developer's evaluate() implementation
        $this->evaluate($output, $datasetItem);

        return new AssertionOutcomes(
            $this->ruleExecutor->getPassedCount(),
            $this->ruleExecutor->getFailedCount(),
            $this->ruleExecutor->getFailures(),
            $this->ruleExecutor->getScores(),
        );
    }

    /**
     * Execute an evaluation rule
     */
    protected function assert(AssertionInterface $rule, mixed $actual): bool
    {
        return $this->ruleExecutor->execute($rule, $actual);
    }
}
