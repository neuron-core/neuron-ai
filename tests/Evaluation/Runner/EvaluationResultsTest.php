<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Tests\Evaluation\Stub\StringContainsEvaluator;
use PHPUnit\Framework\TestCase;

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
