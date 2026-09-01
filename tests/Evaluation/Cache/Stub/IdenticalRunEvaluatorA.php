<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache\Stub;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

class IdenticalRunEvaluatorA extends BaseEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['input' => 'hello'],
        ]);
    }

    // The run() body below is byte-identical to IdenticalRunEvaluatorB::run()
    public function run(array $datasetItem): mixed
    {
        return $datasetItem['input'] . ' world';
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains('world'), $output);
    }
}
