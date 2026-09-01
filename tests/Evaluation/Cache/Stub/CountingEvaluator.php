<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache\Stub;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

class CountingEvaluator extends BaseEvaluator
{
    public static int $runCalls = 0;
    public static int $evaluateCalls = 0;

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['expected' => 'alpha'],
            ['expected' => 'beta'],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        self::$runCalls++;

        return 'output: ' . $datasetItem['expected'];
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        self::$evaluateCalls++;

        $this->assert(new StringContains($datasetItem['expected']), $output);
    }
}
