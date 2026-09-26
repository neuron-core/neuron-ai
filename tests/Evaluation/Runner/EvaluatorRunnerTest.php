<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Tests\Evaluation\Stub\ChildProcessEvaluator;
use NeuronAI\Tests\Evaluation\Stub\FailingItemEvaluator;
use NeuronAI\Tests\Evaluation\Stub\StringContainsEvaluator;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function array_unique;
use function file;
use function file_put_contents;
use function getmypid;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const PHP_EOL;

class EvaluatorRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        ChildProcessEvaluator::$preparedBy = null;
    }

    public function test_assertion_state_does_not_leak_between_dataset_items(): void
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

    public function test_concurrent_run_produces_same_results_as_sequential(): void
    {
        // Exercises the parallel path where pcntl is available (Linux/macOS),
        // and the sequential fallback elsewhere (e.g. Windows)
        $evaluator = new StringContainsEvaluator();
        $runner = new EvaluatorRunner();

        $summary = $runner->run($evaluator, 4);

        $results = $summary->getResults();
        $this->assertCount(2, $results);

        // Results are keyed by their dataset index
        $this->assertEquals(0, $results[0]->getIndex());
        $this->assertEquals(1, $results[1]->getIndex());

        // The stamp must survive the fork boundary
        $this->assertSame(StringContainsEvaluator::class, $results[0]->getEvaluatorClass());

        $this->assertFalse($results[0]->isPassed());
        $this->assertTrue($results[1]->isPassed());

        $this->assertEquals(2, $summary->getTotalAssertions());
        $this->assertEquals(1, $summary->getTotalAssertionsPassed());
        $this->assertEquals(1, $summary->getTotalAssertionsFailed());
    }

    public function test_results_are_stamped_with_the_evaluator_class(): void
    {
        $summary = (new EvaluatorRunner())->run(new StringContainsEvaluator());

        foreach ($summary->getResults() as $result) {
            $this->assertSame(StringContainsEvaluator::class, $result->getEvaluatorClass());
            $this->assertSame('StringContainsEvaluator', $result->getShortEvaluatorClass());
        }
    }

    public function test_child_hooks_run_in_each_child_around_its_item(): void
    {
        $this->requireForking();
        $log = tempnam(sys_get_temp_dir(), 'neuron_child_hooks_');
        $runner = new EvaluatorRunner(
            beforeChild: static function (): void {
                ChildProcessEvaluator::$preparedBy = getmypid();
            },
            afterChild: static function () use ($log): void {
                file_put_contents($log, getmypid() . PHP_EOL, FILE_APPEND);
            },
        );

        try {
            $results = $runner->run(new ChildProcessEvaluator(), 2)->getResults();
            $finishedBy = array_map(intval(...), file($log, FILE_IGNORE_NEW_LINES) ?: []);
        } finally {
            unlink($log);
        }

        $preparedBy = array_map(static fn (EvaluatorResult $result): mixed => $result->getOutput(), $results);
        $this->assertCount(2, array_unique($preparedBy));
        $this->assertNotContains(null, $preparedBy);
        $this->assertNotContains(getmypid(), $preparedBy);
        $this->assertEqualsCanonicalizing($preparedBy, $finishedBy);
        $this->assertNull(ChildProcessEvaluator::$preparedBy);
    }

    public function test_after_child_hook_runs_even_when_the_item_fails(): void
    {
        $this->requireForking();
        $log = (string) tempnam(sys_get_temp_dir(), 'neuron_child_hooks_');
        $runner = new EvaluatorRunner(afterChild: static function () use ($log): void {
            file_put_contents($log, 'released' . PHP_EOL, FILE_APPEND);
        });
        $evaluator = new FailingItemEvaluator([
            ['name' => 'first', 'fail' => 'run'],
            ['name' => 'second', 'fail' => 'evaluate'],
        ]);

        try {
            $results = $runner->run($evaluator, 2)->getResults();
            $released = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        } finally {
            unlink($log);
        }

        $this->assertSame(['released', 'released'], $released);
        $this->assertSame('run failed for first', $results[0]->getError());
        $this->assertSame('evaluate failed for second', $results[1]->getError());
    }

    public function test_child_hooks_do_not_run_without_child_processes(): void
    {
        $runner = new EvaluatorRunner(beforeChild: static function (): void {
            ChildProcessEvaluator::$preparedBy = getmypid();
        });

        $results = $runner->run(new ChildProcessEvaluator())->getResults();

        $this->assertNull($results[0]->getOutput());
        $this->assertNull(ChildProcessEvaluator::$preparedBy);
    }

    /** @return array<string, array{EvaluatorRunner}> */
    public static function failingChildHookProvider(): array
    {
        $fail = static fn () => throw new RuntimeException('connection refused');

        return [
            'before' => [new EvaluatorRunner(beforeChild: $fail)],
            'after' => [new EvaluatorRunner(afterChild: $fail)],
        ];
    }

    #[DataProvider('failingChildHookProvider')]
    public function test_a_failing_child_hook_fails_its_item(EvaluatorRunner $runner): void
    {
        $this->requireForking();

        $results = $runner->run(new ChildProcessEvaluator(), 2)->getResults();

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertInstanceOf(EvaluatorResult::class, $result);
            $this->assertFalse($result->isPassed());
            $this->assertSame('connection refused', $result->getError());
        }
    }

    public function test_an_exception_in_run_fails_only_its_item(): void
    {
        $evaluator = new FailingItemEvaluator([
            ['name' => 'first'],
            ['name' => 'second', 'fail' => 'run'],
            ['name' => 'third'],
        ]);

        $results = (new EvaluatorRunner())->run($evaluator)->getResults();

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]->isPassed());
        $this->assertTrue($results[2]->isPassed());

        $this->assertFalse($results[1]->isPassed());
        $this->assertSame('run failed for second', $results[1]->getError());
        $this->assertNull($results[1]->getOutput());
        $this->assertSame(0, $results[1]->getTotalAssertions());
        $this->assertSame([], $results[1]->getScoreRecords());
        $this->assertSame(['name' => 'second', 'fail' => 'run'], $results[1]->getInput());
    }

    public function test_an_exception_in_evaluate_is_an_item_error_even_after_passing_assertions(): void
    {
        $evaluator = new FailingItemEvaluator([
            ['name' => 'first', 'fail' => 'evaluate'],
            ['name' => 'second'],
        ]);

        $results = (new EvaluatorRunner())->run($evaluator)->getResults();

        $this->assertFalse($results[0]->isPassed());
        $this->assertSame('evaluate failed for first', $results[0]->getError());
        $this->assertSame('output for first', $results[0]->getOutput());
        $this->assertTrue($results[1]->isPassed());
        $this->assertNull($results[1]->getError());
    }

    public function test_concurrent_run_isolates_failing_items(): void
    {
        $this->requireForking();
        $evaluator = new FailingItemEvaluator([
            ['name' => 'first', 'fail' => 'run'],
            ['name' => 'second'],
            ['name' => 'third', 'fail' => 'evaluate'],
        ]);

        $results = (new EvaluatorRunner())->run($evaluator, 3)->getResults();

        $this->assertCount(3, $results);
        foreach ([0, 1, 2] as $index) {
            $this->assertSame($index, $results[$index]->getIndex());
        }
        $this->assertSame('run failed for first', $results[0]->getError());
        $this->assertTrue($results[1]->isPassed());
        $this->assertSame('output for second', $results[1]->getOutput());
        $this->assertSame('evaluate failed for third', $results[2]->getError());
    }

    public function test_set_up_runs_once_per_run_not_per_item(): void
    {
        $evaluator = new FailingItemEvaluator([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);

        (new EvaluatorRunner())->run($evaluator);

        $this->assertSame(1, $evaluator->setUpCalls);
    }

    public function test_result_indices_are_the_dataset_keys(): void
    {
        $evaluator = new FailingItemEvaluator([3 => ['name' => 'a'], 8 => ['name' => 'b']]);

        $results = (new EvaluatorRunner())->run($evaluator)->getResults();

        $this->assertSame([3, 8], array_map(static fn (EvaluatorResult $result): int => $result->getIndex(), $results));
        $this->assertSame('output for b', $results[1]->getOutput());
    }

    public function test_empty_dataset_produces_no_results(): void
    {
        $summary = (new EvaluatorRunner())->run(new FailingItemEvaluator([]), 4);

        $this->assertSame([], $summary->getResults());
        $this->assertFalse($summary->hasFailures());
    }

    public function test_concurrent_run_replaces_non_serializable_outputs_with_a_placeholder(): void
    {
        $this->requireForking();
        $evaluator = new class () extends BaseEvaluator {
            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([['greeting' => 'hello'], ['greeting' => 'ciao']]);
            }

            public function run(array $datasetItem): mixed
            {
                return fn (): string => $datasetItem['greeting'];
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
                $this->assert(new StringContains($datasetItem['greeting']), $output(), 'greeting');
            }
        };

        $results = (new EvaluatorRunner())->run($evaluator, 2)->getResults();

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            // Only the output is replaced: the verdict computed in the child survives
            $this->assertSame('[non-serializable output of type Closure]', $result->getOutput());
            $this->assertTrue($result->isPassed());
            $this->assertSame(1, $result->getAssertionsPassed());
            $this->assertSame('greeting', $result->getScoreRecords()[0]->label);
        }
        $this->assertSame(['greeting' => 'ciao'], $results[1]->getInput());
    }

    public function test_single_item_dataset_runs_in_process_even_with_concurrency(): void
    {
        $evaluator = new class () extends ChildProcessEvaluator {
            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([['item' => 1]]);
            }
        };
        $runner = new EvaluatorRunner(beforeChild: static function (): void {
            ChildProcessEvaluator::$preparedBy = getmypid();
        });

        $results = $runner->run($evaluator, 4)->getResults();

        $this->assertNull($results[0]->getOutput());
    }

    protected function requireForking(): void
    {
        if (!EvaluatorRunner::supportsConcurrency()) {
            $this->markTestSkipped('Child hooks run in forked processes, which require pcntl and spatie/fork.');
        }
    }
}
