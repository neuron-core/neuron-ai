<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Score;
use NeuronAI\Tests\Evaluation\Stub\StringContainsEvaluator;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function array_values;

class EvaluationResultsTest extends TestCase
{
    public function test_exposes_results_and_derived_statistics(): void
    {
        $results = [
            $this->makeResult(0, true, 0.1),
            $this->makeResult(1, false, 0.3),
        ];
        $evaluationResults = new EvaluationResults($results);

        $this->assertSame($results, $evaluationResults->getResults());
        $this->assertSame(2, $evaluationResults->getTotalCount());
        $this->assertSame(1, $evaluationResults->getPassedCount());
        $this->assertSame(1, $evaluationResults->getFailedCount());
        $this->assertEqualsWithDelta(0.2, $evaluationResults->getAverageExecutionTime(), 0.000001);
    }

    public function test_empty_results_have_zero_rates_and_no_failures(): void
    {
        $results = new EvaluationResults([]);

        $this->assertSame(0, $results->getTotalCount());
        $this->assertSame(0.0, $results->getSuccessRate());
        $this->assertSame(0.0, $results->getAssertionSuccessRate());
        $this->assertSame(0.0, $results->getAverageExecutionTime());
        $this->assertFalse($results->hasFailures());
        $this->assertSame([], $results->getScoreStatisticsByLabel());
        $this->assertSame([], $results->getAllAssertionFailures());
    }

    public function test_success_rates_count_items_and_assertions_separately(): void
    {
        $results = new EvaluationResults([
            new EvaluatorResult(StringContainsEvaluator::class, 0, true, [], 'a', 0.1, 3, 0),
            new EvaluatorResult(StringContainsEvaluator::class, 1, false, [], 'b', 0.1, 0, 1),
            new EvaluatorResult(StringContainsEvaluator::class, 2, false, [], null, 0.1, 0, 0, error: 'boom'),
            new EvaluatorResult(StringContainsEvaluator::class, 3, true, [], 'd', 0.1, 0, 0),
        ]);

        $this->assertSame(2, $results->getPassedCount());
        $this->assertSame(2, $results->getFailedCount());
        $this->assertSame(0.5, $results->getSuccessRate());
        $this->assertSame(3, $results->getTotalAssertionsPassed());
        $this->assertSame(1, $results->getTotalAssertionsFailed());
        $this->assertSame(4, $results->getTotalAssertions());
        $this->assertSame(0.75, $results->getAssertionSuccessRate());
        $this->assertTrue($results->hasFailures());
        $this->assertSame([1, 2], array_map(
            static fn (EvaluatorResult $result): int => $result->getIndex(),
            array_values($results->getFailedResults())
        ));
    }

    public function test_cached_runs_are_counted(): void
    {
        $results = new EvaluationResults([
            new EvaluatorResult(StringContainsEvaluator::class, 0, true, [], 'a', 0.1, 1, 0, cachedRun: true),
            new EvaluatorResult(StringContainsEvaluator::class, 1, true, [], 'b', 0.1, 1, 0),
            new EvaluatorResult(StringContainsEvaluator::class, 2, false, [], 'c', 0.1, 0, 1, cachedRun: true),
        ]);

        $this->assertSame(2, $results->getCachedRunCount());
    }

    public function test_failures_and_scores_are_collected_in_item_order(): void
    {
        $first = new AssertionFailure(StringContainsEvaluator::class, 'StringContains', 'first', 10);
        $second = new AssertionFailure(StringContainsEvaluator::class, 'MatchesRegex', 'second', 11);
        $third = new AssertionFailure(StringContainsEvaluator::class, 'StringContains', 'third', 10);
        $results = new EvaluationResults([
            new EvaluatorResult(StringContainsEvaluator::class, 0, false, [], 'a', 0.1, 0, 2, [$first, $second], [
                new Score('accuracy', 0.2, false),
                new Score('tone', 0.9, true),
            ]),
            new EvaluatorResult(StringContainsEvaluator::class, 1, false, [], 'b', 0.1, 0, 1, [$third], [
                new Score('accuracy', 0.4, false),
            ]),
        ]);

        $this->assertSame([$first, $second, $third], $results->getAllAssertionFailures());
        $this->assertSame([0.2, 0.9, 0.4], $results->getAllAssertionScores());
        $this->assertSame(['accuracy', 'tone'], array_keys($results->getScoresByLabel()));
        $this->assertCount(2, $results->getScoresByLabel()['accuracy']);
    }

    public function test_statistics_by_label(): void
    {
        $results = new EvaluationResults([
            new EvaluatorResult(StringContainsEvaluator::class, 0, true, [], 'a', 0.1, 3, 0, [], [
                new Score('accuracy', 0.4, false),
                new Score('accuracy', 0.2, false),
                new Score('tone', 1.0, true),
            ]),
            new EvaluatorResult(StringContainsEvaluator::class, 1, true, [], 'b', 0.1, 1, 0, [], [
                new Score('accuracy', 0.9, true),
            ]),
        ]);

        $statistics = $results->getScoreStatisticsByLabel();

        $this->assertEqualsWithDelta(0.5, $statistics['accuracy']['average'], 1e-12);
        $this->assertSame(0.2, $statistics['accuracy']['min']);
        $this->assertSame(0.9, $statistics['accuracy']['max']);
        $this->assertSame(3, $statistics['accuracy']['count']);
        $this->assertSame(['average' => 1.0, 'min' => 1.0, 'max' => 1.0, 'count' => 1], $statistics['tone']);
        $this->assertEqualsWithDelta(0.625, $results->getAverageAssertionScore(), 1e-12);
        $this->assertSame(0.2, $results->getMinAssertionScore());
        $this->assertSame(1.0, $results->getMaxAssertionScore());
    }

    public function test_result_exposes_failures_scores_and_short_class(): void
    {
        $failure = new AssertionFailure(StringContainsEvaluator::class, 'StringContains', 'missing', 10);
        $withFailure = new EvaluatorResult(StringContainsEvaluator::class, 0, false, [], 'a', 0.1, 1, 1, [$failure], [
            new Score('StringContains', 0.0, false),
            new Score('quality', 0.7, true),
        ]);
        $clean = new EvaluatorResult('GlobalEvaluator', 1, true, [], 'b', 0.1, 1, 0);

        $this->assertTrue($withFailure->hasAssertionFailures());
        $this->assertSame([0.0, 0.7], $withFailure->getAssertionScores());
        $this->assertSame(2, $withFailure->getTotalAssertions());
        $this->assertFalse($clean->hasAssertionFailures());
        $this->assertFalse($clean->hasError());
        $this->assertSame('GlobalEvaluator', $clean->getShortEvaluatorClass());
    }

    protected function makeResult(int $index, bool $passed, float $executionTime): EvaluatorResult
    {
        return new EvaluatorResult(
            StringContainsEvaluator::class,
            $index,
            $passed,
            [],
            'output',
            $executionTime,
            $passed ? 1 : 0,
            $passed ? 0 : 1,
        );
    }
}
