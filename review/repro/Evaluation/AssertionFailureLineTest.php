<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use PHPUnit\Framework\TestCase;

class AssertionFailureLineTest extends TestCase
{
    public function test_each_failure_records_the_evaluator_class_and_the_line_of_its_assert_call(): void
    {
        $evaluator = new class () extends BaseEvaluator {
            /** @var array<int> */
            public array $assertLines = [];

            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([]);
            }

            public function run(array $datasetItem): mixed
            {
                return 'hello';
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
                $this->assertLines[] = __LINE__ + 1;
                $this->assert(new StringContains('missing'), $output);
                $this->assertLines[] = __LINE__ + 1;
                $this->assert(new StringContains('absent'), $output);
            }
        };

        $outcomes = $evaluator->performEvaluation('hello', []);

        $this->assertCount(2, $outcomes->failures);
        $this->assertSame($evaluator->assertLines[0], $outcomes->failures[0]->getLineNumber());
        $this->assertSame($evaluator->assertLines[1], $outcomes->failures[1]->getLineNumber());
        $this->assertSame($evaluator::class, $outcomes->failures[0]->getEvaluatorClass());
    }
}
