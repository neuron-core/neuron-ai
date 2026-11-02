<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Evaluation\Usage\ModelUsage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Evaluation\Cache\Stub\CountingEvaluator;
use NeuronAI\Tests\Evaluation\Cache\Stub\DependentEvaluator;
use NeuronAI\Tests\Evaluation\Stub\EvaluationReportFixture;
use NeuronAI\Tests\Evaluation\Stub\FlakyEvaluator;
use NeuronAI\Tests\Evaluation\Stub\FlakyInterfaceEvaluator;
use PHPUnit\Framework\TestCase;

use function array_count_values;
use function count;
use function file;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function rmdir;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;

class EvaluatorRunnerRerunTest extends TestCase
{
    protected string $attemptsFile;

    protected string $cacheDirectory;

    protected function setUp(): void
    {
        $this->attemptsFile = tempnam(sys_get_temp_dir(), 'neuron-attempts-');
        $this->cacheDirectory = sys_get_temp_dir() . '/' . uniqid('neuron-rerun-');
    }

    protected function tearDown(): void
    {
        DependentEvaluator::$dependencies = [];

        if (is_file($this->attemptsFile)) {
            unlink($this->attemptsFile);
        }

        if (is_dir($this->cacheDirectory)) {
            foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->cacheDirectory);
        }
    }

    public function test_a_failed_item_runs_again_and_keeps_the_verdict_of_its_first_run(): void
    {
        $results = (new EvaluatorRunner(rerunFailed: 3))->run(new FlakyEvaluator(
            [['name' => 'a', 'passes_from' => 3]],
            $this->attemptsFile,
        ));

        $result = $results->getResults()[0];
        $this->assertFalse($result->isPassed());
        $this->assertTrue($results->hasFailures());
        $this->assertCount(3, $result->getReruns());
        $this->assertSame(2, $result->getRerunsPassed());
        $this->assertSame(3, $results->getRerunCount());
        $this->assertSame(['a' => 4], $this->attempts());
    }

    public function test_only_failed_items_run_again(): void
    {
        $results = (new EvaluatorRunner(rerunFailed: 2))->run(new FlakyEvaluator(
            [['name' => 'good', 'passes_from' => 1], ['name' => 'bad', 'passes_from' => 9]],
            $this->attemptsFile,
        ));

        $this->assertSame(['good' => 1, 'bad' => 3], $this->attempts());
        $this->assertSame([], $results->getResults()[0]->getReruns());
        $this->assertCount(2, $results->getResults()[1]->getReruns());
    }

    public function test_an_item_that_errored_does_not_run_again(): void
    {
        (new EvaluatorRunner(rerunFailed: 2))->run(new FlakyEvaluator(
            [['name' => 'a', 'passes_from' => 1, 'error' => true]],
            $this->attemptsFile,
        ));

        $this->assertSame(['a' => 1], $this->attempts());
    }

    public function test_no_item_runs_again_by_default(): void
    {
        $results = (new EvaluatorRunner())->run(new FlakyEvaluator([['name' => 'a', 'passes_from' => 9]], $this->attemptsFile));

        $this->assertSame(['a' => 1], $this->attempts());
        $this->assertSame(0, $results->getRerunCount());
    }

    public function test_a_rerun_neither_reads_nor_writes_the_cache(): void
    {
        $evaluator = new FlakyEvaluator([['name' => 'a', 'passes_from' => 2]], $this->attemptsFile);
        $cache = new FileEvaluationCache($this->cacheDirectory);

        $first = (new EvaluatorRunner(rerunFailed: 1))->withCache($cache)->run($evaluator)->getResults()[0];
        $second = (new EvaluatorRunner(rerunFailed: 1))->withCache($cache)->run($evaluator)->getResults()[0];

        $this->assertFalse($first->isCachedRun());
        $this->assertSame(1, $first->getRerunsPassed());
        $this->assertTrue($second->isCachedRun());
        $this->assertSame('fail', $second->getOutput());
        $this->assertFalse($second->getReruns()[0]->isCachedRun());
        $this->assertSame(['a' => 3], $this->attempts());
    }

    public function test_reruns_run_in_child_processes_and_return_to_their_items(): void
    {
        if (!EvaluatorRunner::supportsConcurrency()) {
            $this->markTestSkipped('Forked reruns require pcntl, posix and spatie/fork.');
        }

        $results = (new EvaluatorRunner(rerunFailed: 2))->run(new FlakyEvaluator(
            [['name' => 'a', 'passes_from' => 9], ['name' => 'b', 'passes_from' => 1], ['name' => 'c', 'passes_from' => 9]],
            $this->attemptsFile,
        ), concurrency: 3);

        $reruns = $results->getResults();
        $this->assertSame(['a', 'a'], [$reruns[0]->getReruns()[0]->getInput()['name'], $reruns[0]->getReruns()[1]->getInput()['name']]);
        $this->assertSame([], $reruns[1]->getReruns());
        $this->assertSame(['c', 'c'], [$reruns[2]->getReruns()[0]->getInput()['name'], $reruns[2]->getReruns()[1]->getInput()['name']]);
    }

    public function test_a_rerun_in_a_child_process_runs_the_child_hooks(): void
    {
        if (!EvaluatorRunner::supportsConcurrency()) {
            $this->markTestSkipped('Forked reruns require pcntl, posix and spatie/fork.');
        }

        $hooksFile = tempnam(sys_get_temp_dir(), 'neuron-hooks-');
        $runner = new EvaluatorRunner(
            beforeChild: static function () use ($hooksFile): void {
                file_put_contents($hooksFile, "before\n", FILE_APPEND);
            },
            rerunFailed: 1,
        );

        $results = $runner->run(new FlakyEvaluator(
            [['name' => 'a', 'passes_from' => 9], ['name' => 'b', 'passes_from' => 9]],
            $this->attemptsFile,
        ), concurrency: 2);

        $this->assertCount(4, file($hooksFile, FILE_IGNORE_NEW_LINES) ?: []);
        $this->assertSame(2, $results->getRerunCount());
        unlink($hooksFile);
    }

    public function test_a_failed_item_of_an_evaluator_outside_the_base_class_runs_again(): void
    {
        $results = (new EvaluatorRunner(rerunFailed: 1))->run(new FlakyInterfaceEvaluator());

        $this->assertCount(1, $results->getResults()[0]->getReruns());
    }

    public function test_a_rerun_that_errored_is_told_apart_from_one_that_failed_its_assertions(): void
    {
        $result = (new EvaluatorRunner(rerunFailed: 2))->run(new FlakyEvaluator(
            [['name' => 'a', 'passes_from' => 9, 'error_from' => 3]],
            $this->attemptsFile,
        ))->getResults()[0];

        $this->assertSame(0, $result->getRerunsPassed());
        $this->assertSame(1, $result->getRerunsErrored());
        $this->assertCount(2, $result->getReruns());
    }

    public function test_the_totals_include_what_the_reruns_spent_on_the_agent_and_the_judges(): void
    {
        $usage = new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10);
        $rerun = new EvaluatorResult('App\\Eval', 0, true, [], 'ok', 0.5, 1, 0, runUsage: [$usage], judgeUsage: [$usage]);
        $results = new EvaluationResults([
            new EvaluatorResult('App\\Eval', 0, false, [], 'no', 1.0, 0, 1, runUsage: [$usage], judgeUsage: [$usage], reruns: [$rerun]),
        ]);

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 2, 200, 20)], $results->getRunUsage());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 2, 200, 20)], $results->getJudgeUsage());
    }

    public function test_attaching_reruns_keeps_everything_measured_on_the_first_attempt(): void
    {
        $usage = new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10);
        $result = new EvaluatorResult('App\\Eval', 3, false, ['q' => 'a'], 'no', 1.0, 0, 1, [], [], null, true, 0.5, 0.25, [$usage], [$usage]);

        $copy = $result->withReruns([$result]);

        $this->assertEquals([3, 'no', true, 0.5, 0.25, [$usage], [$usage], 1], [
            $copy->getIndex(), $copy->getOutput(), $copy->isCachedRun(), $copy->getRunTime(), $copy->getEvaluationTime(),
            $copy->getRunUsage(), $copy->getJudgeUsage(), count($copy->getReruns()),
        ]);
    }

    public function test_a_result_subclass_with_a_property_of_its_own_can_still_be_copied(): void
    {
        $result = new class ('App\\Eval', 0, true, [], 'kept', 1.0, 1, 0) extends EvaluatorResult {
            public string $tag = 'mine';
        };

        $this->assertSame('other', $result->withOutput('other')->getOutput());
        $this->assertCount(1, $result->withReruns([$result])->getReruns());
    }

    public function test_an_output_can_be_replaced_with_null(): void
    {
        $result = new EvaluatorResult('App\\Eval', 0, true, [], 'kept', 1.0, 1, 0);

        $this->assertNull($result->withOutput(null)->getOutput());
    }

    public function test_a_negative_number_of_reruns_is_refused(): void
    {
        $this->expectException(EvaluationException::class);

        new EvaluatorRunner(rerunFailed: -1);
    }

    public function test_with_cache_keeps_the_reruns_of_the_runner(): void
    {
        $runner = (new EvaluatorRunner(rerunFailed: 1))->withCache(new FileEvaluationCache($this->cacheDirectory));

        $runner->run(new FlakyEvaluator([['name' => 'a', 'passes_from' => 9]], $this->attemptsFile));

        $this->assertSame(['a' => 2], $this->attempts());
    }

    public function test_reading_the_cache_with_no_declared_dependencies_warns(): void
    {
        $results = (new EvaluatorRunner())->withCache(new FileEvaluationCache($this->cacheDirectory))->run(new CountingEvaluator());

        $this->assertCount(1, $results->getWarnings());
        $this->assertTrue(str_contains($results->getWarnings()[0], CountingEvaluator::class));
        $this->assertTrue(str_contains($results->getWarnings()[0], 'cacheDependencies()'));
    }

    public function test_an_evaluator_outside_the_base_class_declares_no_dependencies_and_warns(): void
    {
        $results = (new EvaluatorRunner())->withCache(new FileEvaluationCache($this->cacheDirectory))->run(new FlakyInterfaceEvaluator());

        $this->assertCount(1, $results->getWarnings());
    }

    public function test_the_warnings_of_every_evaluator_reach_the_report(): void
    {
        $report = EvaluationReportFixture::report(
            EvaluationReportFixture::evaluatorReport('App\\A', new EvaluationResults([], ['first'])),
            EvaluationReportFixture::evaluatorReport('App\\B', new EvaluationResults([], ['second'])),
        );

        $this->assertSame(['first', 'second'], $report->getResults()->getWarnings());
    }

    public function test_no_warning_with_declared_dependencies_with_fresh_or_with_no_cache(): void
    {
        $cache = new FileEvaluationCache($this->cacheDirectory);
        DependentEvaluator::$dependencies = [__FILE__];

        $this->assertSame([], (new EvaluatorRunner())->withCache($cache)->run(new DependentEvaluator())->getWarnings());
        $this->assertSame([], (new EvaluatorRunner())->withCache($cache, refresh: true)->run(new CountingEvaluator())->getWarnings());
        $this->assertSame([], (new EvaluatorRunner())->run(new CountingEvaluator())->getWarnings());
    }

    /**
     * @return array<string, int>
     */
    protected function attempts(): array
    {
        return array_count_values(file($this->attemptsFile, FILE_IGNORE_NEW_LINES) ?: []);
    }
}
