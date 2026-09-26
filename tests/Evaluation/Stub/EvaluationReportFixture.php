<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use DateTimeImmutable;
use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Score;

/**
 * Deterministic reports for output driver tests
 */
class EvaluationReportFixture
{
    public const EVALUATOR = 'App\\Evals\\SupportEvaluator';

    /**
     * One evaluator, three items: a cached pass, an assertion failure and an error.
     */
    public static function mixed(): EvaluationReport
    {
        return self::report(self::evaluatorReport(self::EVALUATOR, new EvaluationResults([
            new EvaluatorResult(self::EVALUATOR, 0, true, ['q' => 'hi'], 'Hello', 0.25, 1, 0, [], [
                new Score('StringContains', 1.0, true),
            ], cachedRun: true),
            new EvaluatorResult(self::EVALUATOR, 1, false, ['q' => 'refund'], ['status' => 'denied'], 0.5, 1, 1, [
                new AssertionFailure(self::EVALUATOR, 'ToolWasCalled', "Expected tool 'refund_order' to be called", 42),
            ], [
                new Score('ToolWasCalled', 0.0, false),
                new Score('quality', 0.8, true),
            ]),
            new EvaluatorResult(self::EVALUATOR, 2, false, ['q' => 'x'], null, 0.125, 0, 0, [], [], 'Provider timeout'),
        ])));
    }

    /**
     * One evaluator whose single item has the given output and outcome.
     *
     * @param array<AssertionFailure> $failures
     */
    public static function singleResult(mixed $output, bool $passed = true, ?string $error = null, array $failures = []): EvaluationReport
    {
        return self::report(self::evaluatorReport(self::EVALUATOR, new EvaluationResults([
            new EvaluatorResult(self::EVALUATOR, 0, $passed, ['q' => 'input'], $output, 0.5, $passed ? 1 : 0, $passed ? 0 : 1, $failures, [], $error),
        ])));
    }

    public static function evaluatorReport(string $evaluatorClass, EvaluationResults $results, ?string $error = null): EvaluatorReport
    {
        return new EvaluatorReport(
            $evaluatorClass,
            $results,
            new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-03T10:00:02.5+00:00'),
            $error,
        );
    }

    public static function report(EvaluatorReport ...$reports): EvaluationReport
    {
        return new EvaluationReport(
            $reports,
            new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-03T10:00:02.5+00:00'),
        );
    }
}
