<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\AssertionInterface;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Test evaluator whose output is the list of assertions evaluate() executes:
 * each entry is [AssertionInterface $rule, mixed $actual, ?string $label].
 */
class AssertingEvaluator extends BaseEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([]);
    }

    public function run(array $datasetItem): mixed
    {
        return [];
    }

    /**
     * @param array<array{0: AssertionInterface, 1: mixed, 2?: string|null}> $output
     */
    public function evaluate(mixed $output, array $datasetItem): void
    {
        foreach ($output as $assertion) {
            $this->assert($assertion[0], $assertion[1], $assertion[2] ?? null);
        }
    }
}
