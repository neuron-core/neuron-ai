<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Contracts;

use NeuronAI\Evaluation\AssertionOutcomes;

interface EvaluatorInterface
{
    /**
     * Set up the method called before evaluation starts.
     * Override this to initialize judge agents and other resources
     */
    public function setUp(): void;

    /**
     * Get the dataset for this evaluator
     */
    public function getDataset(): DatasetInterface;

    /**
     * Run the application logic being tested
     * @param array<string, mixed> $datasetItem Current item from the dataset
     * @return mixed Output from the application logic
     */
    public function run(array $datasetItem): mixed;

    /**
     * Perform evaluation and return assertion outcomes.
     * Called by the runner to execute evaluate() and collect results.
     * @internal Used by EvaluatorRunner
     */
    public function performEvaluation(mixed $output, array $datasetItem): AssertionOutcomes;
}
