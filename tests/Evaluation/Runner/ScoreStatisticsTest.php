<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Tests\Evaluation\Stubs\FixedScoreAssertion;
use NeuronAI\Tests\Evaluation\Stubs\ScoreBasedEvaluator;
use PHPUnit\Framework\TestCase;

class ScoreStatisticsTest extends TestCase
{
    public function testScoreStatisticsAreTracked(): void
    {
        $evaluator = new ScoreBasedEvaluator([0.8, 0.6, 0.9]);
        $runner = new EvaluatorRunner();
        $summary = $runner->run($evaluator);

        // Summary-level aggregation
        $allScores = $summary->getAllAssertionScores();
        $this->assertCount(3, $allScores);
        $this->assertEquals(0.8, $allScores[0]);
        $this->assertEquals(0.6, $allScores[1]);
        $this->assertEquals(0.9, $allScores[2]);

        $this->assertEqualsWithDelta(0.766, $summary->getAverageAssertionScore(), 0.001);
        $this->assertEquals(0.6, $summary->getMinAssertionScore());
        $this->assertEquals(0.9, $summary->getMaxAssertionScore());
    }

    public function testEmptyScoresReturnZero(): void
    {
        $evaluator = new class () extends BaseEvaluator {
            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([['value' => 1]]);
            }

            public function run(array $datasetItem): mixed
            {
                return $datasetItem['value'];
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
                // No assertions - test empty score handling
            }
        };

        $runner = new EvaluatorRunner();
        $summary = $runner->run($evaluator);

        $this->assertEmpty($summary->getAllAssertionScores());
        $this->assertEquals(0.0, $summary->getAverageAssertionScore());
        $this->assertEquals(0.0, $summary->getMinAssertionScore());
        $this->assertEquals(0.0, $summary->getMaxAssertionScore());
    }

    public function testResultLevelScoreStatistics(): void
    {
        // Create evaluator that runs multiple assertions per item
        $evaluator = new class () extends BaseEvaluator {
            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([
                    ['scores' => [0.5, 0.7]],
                    ['scores' => [0.9]],
                ]);
            }

            public function run(array $datasetItem): mixed
            {
                return $datasetItem['scores'];
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
                foreach ($output as $score) {
                    $this->assert(new FixedScoreAssertion((float) $score), $score);
                }
            }
        };

        $runner = new EvaluatorRunner();
        $summary = $runner->run($evaluator);

        $results = $summary->getResults();

        // First result has 2 assertions with scores 0.5, 0.7
        $result0 = $results[0];
        $this->assertEquals([0.5, 0.7], $result0->getAssertionScores());
        $this->assertEqualsWithDelta(0.6, $result0->getAverageAssertionScore(), 0.001);
        $this->assertEquals(0.5, $result0->getMinAssertionScore());
        $this->assertEquals(0.7, $result0->getMaxAssertionScore());

        // Second result has 1 assertion with score 0.9
        $result1 = $results[1];
        $this->assertEquals([0.9], $result1->getAssertionScores());
        $this->assertEquals(0.9, $result1->getAverageAssertionScore());

        // Summary aggregates all 3 scores
        $this->assertEqualsWithDelta(0.7, $summary->getAverageAssertionScore(), 0.001);
    }
}
