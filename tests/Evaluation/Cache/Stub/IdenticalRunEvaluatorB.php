<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache\Stub;

use NeuronAI\Evaluation\Assertions\StringStartsWith;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

class IdenticalRunEvaluatorB extends BaseEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['input' => 'hello'],
        ]);
    }

    // The run() body below is byte-identical to IdenticalRunEvaluatorA::run()
    public function run(array $datasetItem): mixed
    {
        return $datasetItem['input'] . ' world';
    }

    // Different assertions on purpose: evaluate() must not affect the run() fingerprint
    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringStartsWith('hello'), $output);
    }
}
