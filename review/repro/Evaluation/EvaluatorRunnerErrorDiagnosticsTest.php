<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use PHPUnit\Framework\TestCase;

use function intdiv;

class EvaluatorRunnerErrorDiagnosticsTest extends TestCase
{
    public function test_item_error_identifies_the_exception_type_and_origin(): void
    {
        $evaluator = new class () extends BaseEvaluator {
            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([['divisor' => 0]]);
            }

            public function run(array $datasetItem): mixed
            {
                return intdiv(1, $datasetItem['divisor']);
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
            }
        };

        $error = (new EvaluatorRunner())->run($evaluator)->getResults()[0]->getError();

        $this->assertIsString($error);
        $this->assertStringContainsString('DivisionByZeroError', $error);
        $this->assertStringContainsString('Division by zero', $error);
        $this->assertStringContainsString(__FILE__, $error);
    }
}
