<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stubs;

use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

use function array_map;

/**
 * Test evaluator that produces configurable scores
 */
class ScoreBasedEvaluator extends BaseEvaluator
{
    public function __construct(protected readonly array $scores)
    {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        $items = array_map(fn (float $score): array => ['score' => $score], $this->scores);
        return new ArrayDataset($items);
    }

    public function run(array $datasetItem): mixed
    {
        return $datasetItem['score'];
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new FixedScoreAssertion((float) $output), $output);
    }
}
