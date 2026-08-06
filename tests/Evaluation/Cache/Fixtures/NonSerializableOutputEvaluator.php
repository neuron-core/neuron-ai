<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache\Fixtures;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

class NonSerializableOutputEvaluator extends BaseEvaluator
{
    public static int $runCalls = 0;

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['input' => 'hello'],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        self::$runCalls++;

        // A closure cannot cross serialize() — the item must not be cached
        return fn (): string => 'hello';
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains('hello'), is_callable($output) ? $output() : '');
    }
}
