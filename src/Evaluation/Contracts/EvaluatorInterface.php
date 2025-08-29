<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Contracts;

interface EvaluatorInterface
{
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
     * Evaluate the output against expected results
     * @param mixed $output Output from run() method
     * @param array<string, mixed> $datasetItem Reference dataset item for comparison
     */
    public function evaluate(mixed $output, array $datasetItem): void;
}
