<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use PHPUnit\Framework\TestCase;

class EvaluatorRunnerTest extends TestCase
{
    public function testAssertionStateDoesNotLeakBetweenDatasetItems(): void
    {
        $evaluator = new StringContainsEvaluator();
        $runner = new EvaluatorRunner();

        $summary = $runner->run($evaluator);

        $results = $summary->getResults();
        $this->assertCount(2, $results);

        // First item: failing assertion
        $result0 = $results[0];
        $this->assertFalse($result0->isPassed());
        $this->assertEquals(0, $result0->getAssertionsPassed());
        $this->assertEquals(1, $result0->getAssertionsFailed());
        $this->assertEquals(1, $result0->getTotalAssertions());

        // Second item: passing assertion (should not inherit first item's failures)
        $result1 = $results[1];
        $this->assertTrue($result1->isPassed());
        $this->assertEquals(1, $result1->getAssertionsPassed());
        $this->assertEquals(0, $result1->getAssertionsFailed());
        $this->assertEquals(1, $result1->getTotalAssertions());

        // Summary: exactly 2 assertions total (one per dataset item)
        $this->assertEquals(2, $summary->getTotalAssertions());
        $this->assertEquals(1, $summary->getTotalAssertionsPassed());
        $this->assertEquals(1, $summary->getTotalAssertionsFailed());
    }

}

/**
 * Test evaluator that checks if strings contain expected substrings
 */
class StringContainsEvaluator extends BaseEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['actual' => 'hello', 'expected' => 'world'],
            ['actual' => 'hello world', 'expected' => 'world'],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        return $datasetItem['actual'];
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['expected']), $output);
    }
}

