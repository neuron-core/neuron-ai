<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use DateTimeImmutable;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Tests\Evaluation\Stub\ScoreBasedEvaluator;
use NeuronAI\Tests\Evaluation\Stub\StringContainsEvaluator;
use PHPUnit\Framework\TestCase;

class EvaluationReportTest extends TestCase
{
    public function test_preserves_order_empty_evaluators_and_wall_clock_time(): void
    {
        $firstSummary = new EvaluationResults([
            $this->makeResult(StringContainsEvaluator::class, 0),
        ]);
        $emptySummary = new EvaluationResults([]);
        $firstStartedAt = new DateTimeImmutable('2026-09-03T10:00:00.123456+00:00');
        $firstFinishedAt = new DateTimeImmutable('2026-09-03T10:00:01.373456+00:00');
        $secondStartedAt = new DateTimeImmutable('2026-09-03T10:00:01.373456+00:00');
        $secondFinishedAt = new DateTimeImmutable('2026-09-03T10:00:03.873456+00:00');

        $suite = new EvaluationReport([
            new EvaluatorReport(
                StringContainsEvaluator::class,
                $firstSummary,
                $firstStartedAt,
                $firstFinishedAt,
                namespace: 'App\\Agents\\SupportAgent',
            ),
            new EvaluatorReport(
                ScoreBasedEvaluator::class,
                $emptySummary,
                $secondStartedAt,
                $secondFinishedAt,
            ),
        ], $firstStartedAt, $secondFinishedAt);

        $reports = $suite->getEvaluatorReports();

        $this->assertSame(StringContainsEvaluator::class, $reports[0]->getEvaluatorClass());
        $this->assertSame(ScoreBasedEvaluator::class, $reports[1]->getEvaluatorClass());
        $this->assertSame($emptySummary, $reports[1]->getResults());
        $this->assertSame('App\\Agents\\SupportAgent', $reports[0]->getNamespace());
        $this->assertNull($reports[1]->getNamespace());
        $this->assertSame($firstStartedAt, $reports[0]->getStartedAt());
        $this->assertSame($firstFinishedAt, $reports[0]->getFinishedAt());
        $this->assertSame($secondStartedAt, $reports[1]->getStartedAt());
        $this->assertSame($secondFinishedAt, $reports[1]->getFinishedAt());
        $this->assertCount(1, $suite->getResults()->getResults());
        $this->assertEqualsWithDelta(3.75, $suite->getDuration(), 0.000001);
        $this->assertEqualsWithDelta(1.25, $reports[0]->getDuration(), 0.000001);
        $this->assertEqualsWithDelta(2.5, $reports[1]->getDuration(), 0.000001);
    }

    public function test_evaluator_error_fails_suite_without_creating_item_failure(): void
    {
        $suite = new EvaluationReport(
            [
            new EvaluatorReport(
                StringContainsEvaluator::class,
                new EvaluationResults([]),
                new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
                new DateTimeImmutable('2026-09-03T10:00:01+00:00'),
                'Dataset failed to load',
            ),
        ],
            new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-03T10:00:01+00:00'),
        );

        $this->assertTrue($suite->hasFailures());
        $this->assertFalse($suite->getResults()->hasFailures());
        $this->assertSame('Dataset failed to load', $suite->getEvaluatorReports()[0]->getError());
    }

    public function test_repeated_evaluator_classes_remain_distinct_reports(): void
    {
        $suite = new EvaluationReport(
            [
            new EvaluatorReport(
                StringContainsEvaluator::class,
                new EvaluationResults([]),
                new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
                new DateTimeImmutable('2026-09-03T10:00:01+00:00'),
            ),
            new EvaluatorReport(
                StringContainsEvaluator::class,
                new EvaluationResults([]),
                new DateTimeImmutable('2026-09-03T10:00:01+00:00'),
                new DateTimeImmutable('2026-09-03T10:00:02+00:00'),
            ),
        ],
            new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-03T10:00:02+00:00'),
        );

        $this->assertCount(2, $suite->getEvaluatorReports());
        $this->assertEqualsWithDelta(2.0, $suite->getDuration(), 0.000001);
    }

    protected function makeResult(string $evaluatorClass, int $index): EvaluatorResult
    {
        return new EvaluatorResult($evaluatorClass, $index, true, [], 'output', 0.1, 1, 0);
    }
}
