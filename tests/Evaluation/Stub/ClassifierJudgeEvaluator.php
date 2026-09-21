<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Evaluation\Assertions\ClassifierJudge;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

class ClassifierJudgeEvaluator extends BaseEvaluator
{
    /** @param list<array{output: mixed}> $items */
    public function __construct(protected ClassifierJudge $judge, protected array $items)
    {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset($this->items);
    }

    public function run(array $datasetItem): mixed
    {
        return $datasetItem['output'];
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert($this->judge, $output, 'quality');
    }
}
