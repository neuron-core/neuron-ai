<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console\Evaluation;

use ArrayObject;
use Closure;
use LogicException;
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Tests\Console\Evaluation\Stub\RunCountingEvaluator;
use NeuronAI\Tests\Evaluation\Stub\GreetingEvaluator;
use NeuronAI\Tests\Evaluation\Stub\RecordingOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function fopen;
use function ob_end_clean;
use function ob_start;
use function rewind;
use function stream_get_contents;
use function ob_get_clean;
use function sys_get_temp_dir;

use const PHP_EOL;

class EvaluationCommandTest extends TestCase
{
    /** @var resource */
    protected mixed $errorStream;

    protected function setUp(): void
    {
        RunCountingEvaluator::$runCount = 0;

        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $this->errorStream = $stream;
    }

    public function test_each_dataset_item_runs_exactly_once(): void
    {
        $command = new EvaluationCommand();

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub']);
        ob_end_clean();

        $this->assertEquals(0, $exitCode);

        // The fixture dataset has 2 items: run() must be invoked once per item,
        // not twice (the command used to re-run all evaluators to build the overall summary)
        $this->assertEquals(2, RunCountingEvaluator::$runCount);
    }

    public function test_help_prints_usage_without_running_anything(): void
    {
        $command = new EvaluationCommand(discovery: $this->neverDiscovering());

        [$exitCode, $output] = $this->execute($command, __DIR__ . '/Stub', '--help');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('vendor/bin/neuron evaluation <path> [options]', $output);
        $this->assertSame('', $this->errors());
    }

    public function test_path_is_required(): void
    {
        $command = new EvaluationCommand(discovery: $this->neverDiscovering());

        [$exitCode, $output] = $this->execute($command, '--verbose');

        $this->assertSame(1, $exitCode);
        $this->assertSame('Error: Path argument is required' . PHP_EOL, $this->errors());
        $this->assertStringContainsString('Usage:', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidConcurrency(): iterable
    {
        yield 'zero' => ['--concurrency=0'];
        yield 'negative' => ['--concurrency=-2'];
        yield 'not a number' => ['--concurrency=many'];
        yield 'empty' => ['--concurrency='];
    }

    #[DataProvider('invalidConcurrency')]
    public function test_rejects_invalid_concurrency(string $option): void
    {
        $command = new EvaluationCommand(discovery: $this->neverDiscovering());

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub', $option);

        $this->assertSame(1, $exitCode);
        $this->assertSame('Error: Concurrency must be a positive integer' . PHP_EOL, $this->errors());
    }

    /**
     * @return iterable<string, array{string[]}>
     */
    public static function pathArguments(): iterable
    {
        yield 'positional' => [['/evaluators']];
        yield 'option' => [['--path=/evaluators']];
        yield 'after flags' => [['-v', '--cache', '/evaluators']];
        yield 'first positional wins' => [['/evaluators', '/ignored']];
    }

    /**
     * @param string[] $args
     */
    #[DataProvider('pathArguments')]
    public function test_discovers_evaluators_in_the_given_path(array $args): void
    {
        $discovery = $this->createMock(EvaluatorDiscovery::class);
        $discovery->expects($this->once())->method('discover')->with('/evaluators')->willReturn([]);
        $command = new EvaluationCommand(configLoader: $this->config(), discovery: $discovery);

        [$exitCode] = $this->execute($command, ...$args);

        $this->assertSame(1, $exitCode);
        $this->assertSame('Error: No evaluator classes found in: /evaluators' . PHP_EOL, $this->errors());
    }

    public function test_a_missing_directory_is_reported(): void
    {
        $command = new EvaluationCommand(configLoader: $this->config());

        [$exitCode] = $this->execute($command, __DIR__ . '/does-not-exist');

        $this->assertSame(1, $exitCode);
        $this->assertSame('Error: Directory not found: ' . __DIR__ . '/does-not-exist' . PHP_EOL, $this->errors());
    }

    public function test_concurrency_is_forwarded_to_the_runner(): void
    {
        $runner = $this->createMock(EvaluatorRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->with($this->isInstanceOf(RunCountingEvaluator::class), 3)
            ->willReturn(new EvaluationResults([]));

        $command = new EvaluationCommand(
            configLoader: $this->config(),
            discovery: $this->discovering(RunCountingEvaluator::class),
            runner: $runner,
        );

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub', '--concurrency=3');

        $this->assertSame(0, $exitCode);
    }

    public function test_parallel_runs_report_every_dataset_item(): void
    {
        if (!EvaluatorRunner::supportsConcurrency()) {
            $this->markTestSkipped('Parallel evaluation requires the pcntl extension and spatie/fork.');
        }

        $reports = $this->recordedReports();
        $command = new EvaluationCommand(
            configLoader: $this->config(outputDrivers: [new RecordingOutput($reports)]),
            discovery: $this->discovering(RunCountingEvaluator::class),
        );

        [$exitCode, $output] = $this->execute($command, __DIR__ . '/Stub', '--concurrency=2');

        $this->assertSame(0, $exitCode);
        $this->assertSame("Neuron AI Evaluation Runner\n\n..", $output);
        $results = $this->onlyReport($reports)->getEvaluatorReports()[0]->getResults()->getResults();
        $this->assertSame(['hello world', 'hello world'], array_map(static fn (EvaluatorResult $result): mixed => $result->getOutput(), $results));
    }

    public function test_progress_marks_each_result_and_failures_fail_the_run(): void
    {
        $command = new EvaluationCommand(
            configLoader: $this->config(),
            discovery: $this->discovering(RunCountingEvaluator::class),
            runner: $this->runnerReturning($this->results(true, false, true)),
        );

        [$exitCode, $output] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(1, $exitCode);
        $this->assertSame("Neuron AI Evaluation Runner\n\n.F.", $output);
    }

    public function test_passing_results_succeed(): void
    {
        $command = new EvaluationCommand(
            configLoader: $this->config(),
            discovery: $this->discovering(RunCountingEvaluator::class),
            runner: $this->runnerReturning($this->results(true, true)),
        );

        [$exitCode, $output] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(0, $exitCode);
        $this->assertSame("Neuron AI Evaluation Runner\n\n..", $output);
    }

    public function test_verbose_names_each_evaluator_instead_of_printing_progress(): void
    {
        $command = new EvaluationCommand(
            configLoader: $this->config(),
            discovery: $this->discovering(RunCountingEvaluator::class, RunCountingEvaluator::class),
            runner: $this->runnerReturning($this->results(true, false)),
        );

        [, $output] = $this->execute($command, __DIR__ . '/Stub', '--verbose');

        $this->assertSame(
            "Neuron AI Evaluation Runner\n\n"
            . "Running RunCountingEvaluator... [1/2]\n"
            . "Running RunCountingEvaluator... [2/2]\n",
            $output
        );
    }

    public function test_a_failing_evaluator_does_not_stop_the_others(): void
    {
        $runner = $this->createMock(EvaluatorRunner::class);
        $runner->expects($this->exactly(2))
            ->method('run')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new RuntimeException('Setup failed')),
                $this->results(true),
            );
        $reports = $this->recordedReports();

        $command = new EvaluationCommand(
            configLoader: $this->config(outputDrivers: [new RecordingOutput($reports)]),
            discovery: $this->discovering(RunCountingEvaluator::class, RunCountingEvaluator::class),
            runner: $runner,
        );

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(1, $exitCode);
        $this->assertSame('Error: Failed to run ' . RunCountingEvaluator::class . ': Setup failed' . PHP_EOL, $this->errors());
        [$failed, $passed] = $this->onlyReport($reports)->getEvaluatorReports();
        $this->assertSame('Setup failed', $failed->getError());
        $this->assertSame([], $failed->getResults()->getResults());
        $this->assertNull($passed->getError());
        $this->assertSame(1, $passed->getResults()->getPassedCount());
    }

    public function test_reports_carry_the_evaluator_namespace_whether_it_passed_or_failed(): void
    {
        $runner = $this->createMock(EvaluatorRunner::class);
        $runner->method('run')->willReturnOnConsecutiveCalls(
            $this->throwException(new RuntimeException('Setup failed')),
            $this->results(true),
        );
        $reports = $this->recordedReports();

        $command = new EvaluationCommand(
            configLoader: $this->config(outputDrivers: [new RecordingOutput($reports)]),
            discovery: $this->discovering(RunCountingEvaluator::class, RunCountingEvaluator::class),
            runner: $runner,
            resolver: static fn (string $class): object => new class () extends RunCountingEvaluator {
                public function namespace(): string
                {
                    return 'SupportAgent';
                }
            },
        );

        $this->execute($command, __DIR__ . '/Stub');

        [$failed, $passed] = $this->onlyReport($reports)->getEvaluatorReports();
        $this->assertSame('SupportAgent', $failed->getNamespace());
        $this->assertSame('SupportAgent', $passed->getNamespace());
    }

    public function test_evaluator_errors_are_included_in_the_suite_output(): void
    {
        $discovery = $this->createMock(EvaluatorDiscovery::class);
        $discovery->method('discover')->willReturn([RunCountingEvaluator::class]);

        $runner = $this->createMock(EvaluatorRunner::class);
        $runner->method('run')->willThrowException(new RuntimeException('Setup failed'));

        $command = new EvaluationCommand(
            discovery: $discovery,
            runner: $runner,
        );

        [$exitCode, $output] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('RunCountingEvaluator', $output);
        $this->assertStringContainsString('Error: Setup failed', $output);
        $this->assertStringContainsString('FAILURES!', $output);
        $this->assertStringNotContainsString('OK', $output);
    }

    public function test_evaluators_and_output_drivers_are_built_by_the_resolver(): void
    {
        $reports = $this->recordedReports();
        $command = new EvaluationCommand(
            configLoader: $this->config(outputDrivers: [RecordingOutput::class]),
            discovery: $this->discovering(GreetingEvaluator::class),
            resolver: $this->containerResolver('Hello', $reports),
        );

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(0, $exitCode);
        $this->assertSame('Hello, Ada', $this->firstOutput($reports));
    }

    public function test_the_resolver_defaults_to_the_configured_one(): void
    {
        $reports = $this->recordedReports();
        $command = new EvaluationCommand(
            configLoader: $this->config(
                outputDrivers: [RecordingOutput::class],
                resolver: $this->containerResolver('Hi', $reports),
            ),
            discovery: $this->discovering(GreetingEvaluator::class),
        );

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(0, $exitCode);
        $this->assertSame('Hi, Ada', $this->firstOutput($reports));
    }

    public function test_the_constructor_resolver_wins_over_the_configured_one(): void
    {
        $reports = $this->recordedReports();
        $command = new EvaluationCommand(
            configLoader: $this->config(
                outputDrivers: [RecordingOutput::class],
                resolver: static fn (string $class): object => throw new LogicException('The configured resolver was used'),
            ),
            discovery: $this->discovering(GreetingEvaluator::class),
            resolver: $this->containerResolver('Hello', $reports),
        );

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(0, $exitCode);
        $this->assertSame('Hello, Ada', $this->firstOutput($reports));
    }

    public function test_without_a_resolver_an_evaluator_needing_arguments_points_to_one(): void
    {
        $command = new EvaluationCommand(
            configLoader: $this->config(),
            discovery: $this->discovering(GreetingEvaluator::class),
        );

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            GreetingEvaluator::class . ' requires constructor arguments: build it with a resolver',
            $this->errors()
        );
    }

    /**
     * @return iterable<string, array{string[], bool}>
     */
    public static function cacheOptions(): iterable
    {
        yield 'cache' => [['--cache'], false];
        yield 'fresh' => [['--fresh'], true];
        yield 'cache and fresh' => [['--cache', '--fresh'], true];
    }

    /**
     * @param string[] $options
     */
    #[DataProvider('cacheOptions')]
    public function test_cache_options_apply_to_the_injected_runner(array $options, bool $refresh): void
    {
        $runner = $this->createMock(EvaluatorRunner::class);
        $runner->expects($this->once())
            ->method('withCache')
            ->with($this->isInstanceOf(FileEvaluationCache::class), $refresh)
            ->willReturnSelf();
        $runner->expects($this->once())->method('run')->willReturn(new EvaluationResults([]));

        $command = new EvaluationCommand(
            configLoader: $this->config(),
            discovery: $this->discovering(RunCountingEvaluator::class),
            runner: $runner,
        );

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub', ...$options);

        $this->assertSame(0, $exitCode);
    }

    public function test_cache_is_off_by_default(): void
    {
        $runner = $this->createMock(EvaluatorRunner::class);
        $runner->expects($this->never())->method('withCache');
        $runner->expects($this->once())->method('run')->willReturn(new EvaluationResults([]));

        $command = new EvaluationCommand(
            configLoader: $this->config(),
            discovery: $this->discovering(RunCountingEvaluator::class),
            runner: $runner,
        );

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(0, $exitCode);
    }

    public function test_the_runner_defaults_to_the_configured_one(): void
    {
        $runner = $this->createMock(EvaluatorRunner::class);
        $runner->expects($this->once())->method('run')->willReturn(new EvaluationResults([]));

        $command = new EvaluationCommand(
            configLoader: $this->config(runner: $runner),
            discovery: $this->discovering(RunCountingEvaluator::class),
        );

        [$exitCode] = $this->execute($command, __DIR__ . '/Stub');

        $this->assertSame(0, $exitCode);
    }

    /**
     * @return array{int, string} The exit code and the standard output.
     */
    protected function execute(EvaluationCommand $command, string ...$args): array
    {
        $command->setErrorStream($this->errorStream);

        ob_start();
        $exitCode = $command->run(['evaluation', ...$args]);

        return [$exitCode, (string) ob_get_clean()];
    }

    protected function errors(): string
    {
        rewind($this->errorStream);
        return (string) stream_get_contents($this->errorStream);
    }

    /**
     * Stands in for an application container: builds the stubs that need constructor arguments.
     *
     * @param ArrayObject<int, EvaluationReport> $reports
     */
    protected function containerResolver(string $greeting, ArrayObject $reports): Closure
    {
        return static fn (string $class): object => $class === GreetingEvaluator::class
            ? new GreetingEvaluator($greeting)
            : new RecordingOutput($reports);
    }

    /**
     * @param array<int, string|EvaluationOutputInterface> $outputDrivers
     */
    protected function config(array $outputDrivers = [], ?Closure $resolver = null, ?EvaluatorRunner $runner = null): ConfigLoader
    {
        $config = $this->createMock(ConfigLoader::class);
        $config->method('getOutputDrivers')->willReturn($outputDrivers);
        $config->method('getResolver')->willReturn($resolver);
        $config->method('getRunner')->willReturn($runner);
        $config->method('getCachePath')->willReturn(sys_get_temp_dir() . '/neuron-unused-evaluation-cache');

        return $config;
    }

    protected function discovering(string ...$evaluatorClasses): EvaluatorDiscovery
    {
        $discovery = $this->createMock(EvaluatorDiscovery::class);
        $discovery->method('discover')->willReturn($evaluatorClasses);

        return $discovery;
    }

    protected function neverDiscovering(): EvaluatorDiscovery
    {
        $discovery = $this->createMock(EvaluatorDiscovery::class);
        $discovery->expects($this->never())->method('discover');

        return $discovery;
    }

    protected function runnerReturning(EvaluationResults $results): EvaluatorRunner
    {
        $runner = $this->createMock(EvaluatorRunner::class);
        $runner->method('run')->willReturn($results);

        return $runner;
    }

    protected function results(bool ...$passed): EvaluationResults
    {
        $results = [];
        foreach ($passed as $index => $itemPassed) {
            $results[] = new EvaluatorResult(RunCountingEvaluator::class, $index, $itemPassed, [], null, 0.0, (int) $itemPassed, (int) !$itemPassed);
        }

        return new EvaluationResults($results);
    }

    /**
     * @return ArrayObject<int, EvaluationReport>
     */
    protected function recordedReports(): ArrayObject
    {
        return new ArrayObject();
    }

    /**
     * @param ArrayObject<int, EvaluationReport> $reports
     */
    protected function onlyReport(ArrayObject $reports): EvaluationReport
    {
        $this->assertCount(1, $reports);

        return $reports[0];
    }

    /**
     * @param ArrayObject<int, EvaluationReport> $reports
     */
    protected function firstOutput(ArrayObject $reports): mixed
    {
        return $this->onlyReport($reports)->getEvaluatorReports()[0]->getResults()->getResults()[0]->getOutput();
    }
}
