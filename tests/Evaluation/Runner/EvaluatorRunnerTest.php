<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Tests\Evaluation\Stub\ChildProcessEvaluator;
use NeuronAI\Tests\Evaluation\Stub\StringContainsEvaluator;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
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

        // Results must come back in dataset order
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

    /** @dataProvider failingChildHookProvider */
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

    protected function requireForking(): void
    {
        if (!EvaluatorRunner::supportsConcurrency()) {
            $this->markTestSkipped('Child hooks run in forked processes, which require pcntl and spatie/fork.');
        }
    }
}
