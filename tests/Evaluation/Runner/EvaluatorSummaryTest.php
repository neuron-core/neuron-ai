<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;
use NeuronAI\Tests\Evaluation\Stub\ScoreBasedEvaluator;
use NeuronAI\Tests\Evaluation\Stub\StringContainsEvaluator;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;

class EvaluatorSummaryTest extends TestCase
{
    public function test_results_are_grouped_by_evaluator_class(): void
    {
        // The CLI merges every evaluator's results into one summary, so indexes
        // collide across evaluators: only the stamp tells them apart.
        $summary = new EvaluatorSummary([
            $this->makeResult(StringContainsEvaluator::class, 0),
            $this->makeResult(ScoreBasedEvaluator::class, 0),
            $this->makeResult(StringContainsEvaluator::class, 1),
        ], 0.3);

        $grouped = $summary->getResultsByEvaluatorClass();

        $this->assertSame([StringContainsEvaluator::class, ScoreBasedEvaluator::class], array_keys($grouped));
        $this->assertSame([0, 1], $this->indexes($grouped[StringContainsEvaluator::class]));
        $this->assertSame([0], $this->indexes($grouped[ScoreBasedEvaluator::class]));
    }

    protected function makeResult(string $evaluatorClass, int $index): EvaluatorResult
    {
        return new EvaluatorResult($evaluatorClass, $index, true, [], 'output', 0.1, 1, 0);
    }

    /**
     * @param array<EvaluatorResult> $results
     * @return array<int>
     */
    protected function indexes(array $results): array
    {
        return array_map(fn (EvaluatorResult $result): int => $result->getIndex(), $results);
    }
}
