<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache\Stub;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

class DependentEvaluator extends BaseEvaluator
{
    /** @var array<string> */
    public static array $dependencies = [];

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['input' => 'hello'],
        ]);
    }

    public function cacheDependencies(): array
    {
        return self::$dependencies;
    }

    public function run(array $datasetItem): mixed
    {
        return $datasetItem['input'];
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains('hello'), $output);
    }
}
