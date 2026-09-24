<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Test evaluator with a constructor dependency, which only a resolver can build
 */
class GreetingEvaluator extends BaseEvaluator
{
    public function __construct(protected string $greeting)
    {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['name' => 'Ada'],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        return "{$this->greeting}, {$datasetItem['name']}";
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['name']), $output);
    }
}
