<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Evaluation\AssertionOutcomes;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Test evaluator built on the interface alone: its item always fails, with a
 * failed assertion it records no AssertionFailure for.
 */
class FlakyInterfaceEvaluator implements EvaluatorInterface
{
    public function namespace(): ?string
    {
        return null;
    }

    public function setUp(): void
    {
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([['name' => 'a']]);
    }

    public function run(array $datasetItem): mixed
    {
        return 'output';
    }

    public function performEvaluation(mixed $output, array $datasetItem): AssertionOutcomes
    {
        return new AssertionOutcomes(0, 1);
    }
}
