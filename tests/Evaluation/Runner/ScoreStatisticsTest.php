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

use function array_keys;

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

        // Second result has 1 assertion with score 0.9
        $result1 = $results[1];
        $this->assertEquals([0.9], $result1->getAssertionScores());

        // Summary aggregates all 3 scores
        $this->assertEqualsWithDelta(0.7, $summary->getAverageAssertionScore(), 0.001);
    }

    public function testScoreLabelDefaultsToAssertionName(): void
    {
        $evaluator = new ScoreBasedEvaluator([0.8, 0.6]);
        $runner = new EvaluatorRunner();
        $summary = $runner->run($evaluator);

        $scores = $summary->getAllScoreRecords();
        $this->assertCount(2, $scores);
        $this->assertEquals('FixedScoreAssertion', $scores[0]->label);
        $this->assertEquals(0.8, $scores[0]->value);
        $this->assertTrue($scores[0]->passed);

        $this->assertEquals(['FixedScoreAssertion'], array_keys($summary->getScoresByLabel()));
    }

    public function testExplicitLabelOverridesAssertionName(): void
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
                $this->assert(new FixedScoreAssertion(0.85), $output, 'task_completion');
            }
        };

        $runner = new EvaluatorRunner();
        $summary = $runner->run($evaluator);

        $scores = $summary->getAllScoreRecords();
        $this->assertCount(1, $scores);
        $this->assertEquals('task_completion', $scores[0]->label);
        $this->assertEquals(0.85, $scores[0]->value);
    }

    public function testScoreStatisticsByLabel(): void
    {
        $evaluator = new class () extends BaseEvaluator {
            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([
                    ['accuracy' => 0.5, 'helpfulness' => 0.7],
                    ['accuracy' => 0.9, 'helpfulness' => 0.3],
                ]);
            }

            public function run(array $datasetItem): mixed
            {
                return $datasetItem;
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
                $this->assert(new FixedScoreAssertion((float) $output['accuracy']), $output, 'accuracy');
                $this->assert(new FixedScoreAssertion((float) $output['helpfulness']), $output, 'helpfulness');
            }
        };

        $runner = new EvaluatorRunner();
        $summary = $runner->run($evaluator);

        $statistics = $summary->getScoreStatisticsByLabel();
        $this->assertEquals(['accuracy', 'helpfulness'], array_keys($statistics));

        $this->assertEqualsWithDelta(0.7, $statistics['accuracy']['average'], 0.001);
        $this->assertEquals(0.5, $statistics['accuracy']['min']);
        $this->assertEquals(0.9, $statistics['accuracy']['max']);
        $this->assertEquals(2, $statistics['accuracy']['count']);

        $this->assertEqualsWithDelta(0.5, $statistics['helpfulness']['average'], 0.001);
        $this->assertEquals(2, $statistics['helpfulness']['count']);

        // The flat float view stays in sync with the labeled records
        $this->assertEquals([0.5, 0.7, 0.9, 0.3], $summary->getAllAssertionScores());
    }
}
