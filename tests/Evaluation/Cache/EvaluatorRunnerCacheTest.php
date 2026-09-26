<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Tests\Evaluation\Cache\Stub\CountingEvaluator;
use NeuronAI\Tests\Evaluation\Cache\Stub\NonSerializableOutputEvaluator;
use NeuronAI\Tests\Evaluation\Stub\FailingItemEvaluator;
use PHPUnit\Framework\TestCase;

use function array_map;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class EvaluatorRunnerCacheTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-eval-cache-' . uniqid();
        CountingEvaluator::$runCalls = 0;
        CountingEvaluator::$evaluateCalls = 0;
        NonSerializableOutputEvaluator::$runCalls = 0;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            array_map(unlink(...), glob($this->directory . '/*') ?: []);
            rmdir($this->directory);
        }
    }

    public function test_second_run_serves_from_cache_and_still_evaluates(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $first = (new EvaluatorRunner($cache))->run(new CountingEvaluator());

        $this->assertSame(2, CountingEvaluator::$runCalls);
        $this->assertSame(0, $first->getCachedRunCount());

        $second = (new EvaluatorRunner($cache))->run(new CountingEvaluator());

        // run() not called again, but evaluate() executed for every item on both runs
        $this->assertSame(2, CountingEvaluator::$runCalls);
        $this->assertSame(4, CountingEvaluator::$evaluateCalls);
        $this->assertSame(2, $second->getCachedRunCount());

        foreach ($second->getResults() as $result) {
            $this->assertTrue($result->isCachedRun());
            $this->assertTrue($result->isPassed());
        }
    }

    public function test_refresh_bypasses_cache_reads(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        (new EvaluatorRunner($cache))->run(new CountingEvaluator());
        $summary = (new EvaluatorRunner($cache, refresh: true))->run(new CountingEvaluator());

        $this->assertSame(4, CountingEvaluator::$runCalls);
        $this->assertSame(0, $summary->getCachedRunCount());
    }

    public function test_with_cache_returns_a_caching_copy_and_leaves_the_runner_unchanged(): void
    {
        $runner = new EvaluatorRunner();
        $cached = $runner->withCache(new FileEvaluationCache($this->directory));

        $cached->run(new CountingEvaluator());
        $second = $cached->run(new CountingEvaluator());
        $uncached = $runner->run(new CountingEvaluator());

        $this->assertSame(2, $second->getCachedRunCount());
        $this->assertSame(0, $uncached->getCachedRunCount());
        $this->assertSame(4, CountingEvaluator::$runCalls);
    }

    public function test_with_cache_can_bypass_cache_reads_while_recording(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        (new EvaluatorRunner())->withCache($cache)->run(new CountingEvaluator());
        $fresh = (new EvaluatorRunner())->withCache($cache, refresh: true)->run(new CountingEvaluator());
        $cached = (new EvaluatorRunner(refresh: true))->withCache($cache)->run(new CountingEvaluator());

        $this->assertSame(0, $fresh->getCachedRunCount());
        $this->assertSame(2, $cached->getCachedRunCount());
        $this->assertSame(4, CountingEvaluator::$runCalls);
    }

    public function test_without_cache_every_run_executes(): void
    {
        (new EvaluatorRunner())->run(new CountingEvaluator());
        $summary = (new EvaluatorRunner())->run(new CountingEvaluator());

        $this->assertSame(4, CountingEvaluator::$runCalls);
        $this->assertSame(0, $summary->getCachedRunCount());
    }

    public function test_non_serializable_output_is_never_cached(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $first = (new EvaluatorRunner($cache))->run(new NonSerializableOutputEvaluator());
        $second = (new EvaluatorRunner($cache))->run(new NonSerializableOutputEvaluator());

        $this->assertSame(2, NonSerializableOutputEvaluator::$runCalls);
        $this->assertSame(0, $second->getCachedRunCount());
        $this->assertTrue($first->getResults()[0]->isPassed());
        $this->assertTrue($second->getResults()[0]->isPassed());
    }

    public function test_refresh_still_records_outputs_for_later_runs(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $fresh = (new EvaluatorRunner($cache, refresh: true))->run(new CountingEvaluator());
        $cached = (new EvaluatorRunner($cache))->run(new CountingEvaluator());

        $this->assertSame(0, $fresh->getCachedRunCount());
        $this->assertSame(2, $cached->getCachedRunCount());
        $this->assertSame(2, CountingEvaluator::$runCalls);
    }

    public function test_cache_is_content_addressed_by_item_not_by_position(): void
    {
        $cache = new FileEvaluationCache($this->directory);
        (new EvaluatorRunner($cache))->run(new FailingItemEvaluator([['name' => 'alpha'], ['name' => 'beta']]));

        $reordered = (new EvaluatorRunner($cache))->run(new FailingItemEvaluator([
            ['name' => 'beta'],
            ['name' => 'gamma'],
            ['name' => 'alpha'],
        ]));

        $results = $reordered->getResults();
        $this->assertSame(
            ['output for beta', 'output for gamma', 'output for alpha'],
            array_map(static fn (EvaluatorResult $result): mixed => $result->getOutput(), $results)
        );
        $this->assertSame([true, false, true], array_map(static fn (EvaluatorResult $result): bool => $result->isCachedRun(), $results));
    }

    public function test_a_failed_run_is_not_cached(): void
    {
        $cache = new FileEvaluationCache($this->directory);
        $items = [['name' => 'alpha', 'fail' => 'run']];

        (new EvaluatorRunner($cache))->run(new FailingItemEvaluator($items));
        $second = (new EvaluatorRunner($cache))->run(new FailingItemEvaluator($items));

        $this->assertSame(0, $second->getCachedRunCount());
        $this->assertSame('run failed for alpha', $second->getResults()[0]->getError());
        $this->assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function test_output_of_an_item_that_fails_evaluation_is_still_cached(): void
    {
        // The cache stores run() outputs, never verdicts: a failing evaluate() is re-run against it
        $cache = new FileEvaluationCache($this->directory);
        $items = [['name' => 'alpha', 'fail' => 'evaluate']];

        (new EvaluatorRunner($cache))->run(new FailingItemEvaluator($items));
        $second = (new EvaluatorRunner($cache))->run(new FailingItemEvaluator($items));

        $result = $second->getResults()[0];
        $this->assertTrue($result->isCachedRun());
        $this->assertSame('output for alpha', $result->getOutput());
        $this->assertSame('evaluate failed for alpha', $result->getError());
    }

    public function test_items_that_cannot_be_fingerprinted_always_run(): void
    {
        $cache = new FileEvaluationCache($this->directory);
        $items = [['name' => 'alpha', 'callback' => static fn (): string => 'not serializable']];

        (new EvaluatorRunner($cache))->run(new FailingItemEvaluator($items));
        $second = (new EvaluatorRunner($cache))->run(new FailingItemEvaluator($items));

        $this->assertSame(0, $second->getCachedRunCount());
        $this->assertTrue($second->getResults()[0]->isPassed());
        $this->assertFalse(is_dir($this->directory));
    }

    public function test_a_cached_null_output_is_served_as_a_cache_hit(): void
    {
        $cache = new FileEvaluationCache($this->directory);
        $evaluator = new class () extends CountingEvaluator {
            public function run(array $datasetItem): mixed
            {
                self::$runCalls++;
                return null;
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
            }
        };

        (new EvaluatorRunner($cache))->run($evaluator);
        $second = (new EvaluatorRunner($cache))->run($evaluator);

        $this->assertSame(2, CountingEvaluator::$runCalls);
        $this->assertSame(2, $second->getCachedRunCount());
        $this->assertNull($second->getResults()[0]->getOutput());
    }
}
