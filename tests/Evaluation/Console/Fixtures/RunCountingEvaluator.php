<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Console\Fixtures;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Test evaluator that counts how many times run() is invoked
 */
class RunCountingEvaluator extends BaseEvaluator
{
    public static int $runCount = 0;

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['actual' => 'hello world', 'expected' => 'world'],
            ['actual' => 'hello world', 'expected' => 'hello'],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        self::$runCount++;
        return $datasetItem['actual'];
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['expected']), $output);
    }
}
