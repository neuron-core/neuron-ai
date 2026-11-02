<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use RuntimeException;

use function file;
use function file_put_contents;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const PHP_INT_MAX;

/**
 * Test evaluator whose items pass from the attempt given in 'passes_from', 1
 * being the first: attempts are counted in a file, so forked children see them
 * too. An item with 'error' throws from run() on every attempt, and one with
 * 'error_from' from that attempt on.
 */
class FlakyEvaluator extends BaseEvaluator
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(protected array $items, protected string $attemptsFile)
    {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset($this->items);
    }

    public function run(array $datasetItem): mixed
    {
        file_put_contents($this->attemptsFile, "{$datasetItem['name']}\n", FILE_APPEND);

        if (($datasetItem['error'] ?? false) || $this->attempts($datasetItem['name']) >= ($datasetItem['error_from'] ?? PHP_INT_MAX)) {
            throw new RuntimeException("run failed for {$datasetItem['name']}");
        }

        return $this->attempts($datasetItem['name']) >= $datasetItem['passes_from'] ? 'pass' : 'fail';
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains('pass'), $output);
    }

    protected function attempts(string $name): int
    {
        $attempts = 0;

        foreach (file($this->attemptsFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $attempts += $line === $name ? 1 : 0;
        }

        return $attempts;
    }
}
