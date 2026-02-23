<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stubs;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Test evaluator that checks if strings contain expected substrings
 */
class StringContainsEvaluator extends BaseEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['actual' => 'hello', 'expected' => 'world'],
            ['actual' => 'hello world', 'expected' => 'world'],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        return $datasetItem['actual'];
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['expected']), $output);
    }
}
