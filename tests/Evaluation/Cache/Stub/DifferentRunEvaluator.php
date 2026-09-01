<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache\Stub;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

class DifferentRunEvaluator extends BaseEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['input' => 'hello'],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        return $datasetItem['input'] . ' universe';
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains('universe'), $output);
    }
}
