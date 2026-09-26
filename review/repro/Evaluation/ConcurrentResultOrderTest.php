<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use DateTimeImmutable;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;
use function json_decode;
use function ob_get_clean;
use function ob_start;
use function usleep;

class ConcurrentResultOrderTest extends TestCase
{
    public function test_concurrent_results_are_in_dataset_order_even_when_the_first_item_finishes_last(): void
    {
        if (!EvaluatorRunner::supportsConcurrency()) {
            $this->markTestSkipped('Requires pcntl and spatie/fork.');
        }

        $evaluator = new class () extends BaseEvaluator {
            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([['name' => 'slow'], ['name' => 'fast']]);
            }

            public function run(array $datasetItem): mixed
            {
                if ($datasetItem['name'] === 'slow') {
                    usleep(200_000);
                }

                return $datasetItem['name'];
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
                $this->assert(new StringContains($datasetItem['name']), $output);
            }
        };

        $results = (new EvaluatorRunner())->run($evaluator, 2);

        $this->assertSame([0, 1], array_keys($results->getResults()));

        $at = new DateTimeImmutable('2026-09-03T10:00:00+00:00');
        ob_start();
        (new JsonOutput())->output(new EvaluationReport([new EvaluatorReport($evaluator::class, $results, $at, $at)], $at, $at));
        $document = json_decode((string) ob_get_clean(), true);

        // The merged report (console failure list, JSON results) inherits the completion order
        $this->assertSame([0, 1], array_column($document['results'], 'index'));
    }
}
