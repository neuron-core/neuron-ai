<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use RuntimeException;

/**
 * Test evaluator whose dataset items decide where the item fails:
 * 'fail' => 'run' throws from run(), 'evaluate' throws from evaluate()
 * after one passing assertion, anything else passes.
 */
class FailingItemEvaluator extends BaseEvaluator
{
    public int $setUpCalls = 0;

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(protected array $items)
    {
        parent::__construct();
    }

    public function setUp(): void
    {
        $this->setUpCalls++;
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset($this->items);
    }

    public function run(array $datasetItem): mixed
    {
        if (($datasetItem['fail'] ?? null) === 'run') {
            throw new RuntimeException("run failed for {$datasetItem['name']}");
        }

        return "output for {$datasetItem['name']}";
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['name']), $output);

        if (($datasetItem['fail'] ?? null) === 'evaluate') {
            throw new RuntimeException("evaluate failed for {$datasetItem['name']}");
        }
    }
}
