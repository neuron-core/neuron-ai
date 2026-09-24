<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Test evaluator whose output is the process a child hook prepared, if any
 */
class ChildProcessEvaluator extends BaseEvaluator
{
    public static ?int $preparedBy = null;

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['item' => 1],
            ['item' => 2],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        return self::$preparedBy;
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
    }
}
