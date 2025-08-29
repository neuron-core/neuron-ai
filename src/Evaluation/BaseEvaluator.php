<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Contracts\AssertionInterface;

abstract class BaseEvaluator implements EvaluatorInterface
{
    private readonly RuleExecutor $ruleExecutor;

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
     * Run the application logic being tested
     *
     * @param array<string, mixed> $datasetItem Current item from the dataset
     * @return mixed Output from the application logic
     */
    abstract public function run(array $datasetItem): mixed;

    /**
     * Evaluate the output against expected results
     *
     * @param mixed $output Output from the run () method
     * @param array<string, mixed> $datasetItem Reference dataset item for comparison
     */
    abstract public function evaluate(mixed $output, array $datasetItem): void;

    /**
     * Execute an evaluation rule
     */
    protected function assert(AssertionInterface $rule, mixed $actual): bool
    {
        return $this->ruleExecutor->execute($rule, $actual);
    }

    /**
     * Get the number of passed assertions
     */
    public function getAssertionsPassed(): int
    {
        return $this->ruleExecutor->getPassedCount();
    }

    /**
     * Get the number of failed assertions
     */
    public function getAssertionsFailed(): int
    {
        return $this->ruleExecutor->getFailedCount();
    }

    /**
     * Get the total number of assertions
     */
    public function getTotalAssertions(): int
    {
        return $this->ruleExecutor->getTotalCount();
    }

    /**
     * Get all assertion failures
     *
     * @return array<AssertionFailure>
     */
    public function getAssertionFailures(): array
    {
        return $this->ruleExecutor->getFailures();
    }
}
