<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console\Evaluation;

use ArrayObject;
use Closure;
use LogicException;
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Tests\Console\Evaluation\Stub\RunCountingEvaluator;
use NeuronAI\Tests\Evaluation\Stub\GreetingEvaluator;
use NeuronAI\Tests\Evaluation\Stub\RecordingOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fopen;
use function ob_end_clean;
use function ob_start;
use function rewind;
use function stream_get_contents;
use function ob_get_clean;
use function sys_get_temp_dir;

class EvaluationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        RunCountingEvaluator::$runCount = 0;
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

    public function test_accepts_concurrency_option(): void
    {
        $command = new EvaluationCommand();

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub', '--concurrency=2']);
        ob_end_clean();

        $this->assertEquals(0, $exitCode);
    }

    public function test_rejects_invalid_concurrency(): void
    {
        $command = new EvaluationCommand();
        /** @var resource $stream */
        $stream = fopen('php://memory', 'r+');
        $command->setErrorStream($stream);

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub', '--concurrency=0']);
        ob_end_clean();

        $this->assertEquals(1, $exitCode);

        rewind($stream);
        $this->assertStringContainsString(
            'Concurrency must be a positive integer',
            (string) stream_get_contents($stream)
        );
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
        /** @var resource $errorStream */
        $errorStream = fopen('php://memory', 'r+');
        $command->setErrorStream($errorStream);

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub']);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('RunCountingEvaluator', $output);
        $this->assertStringContainsString('Error: Setup failed', $output);
        $this->assertStringContainsString('FAILURES!', $output);
        $this->assertStringNotContainsString('OK', $output);
    }

    public function test_evaluators_and_output_drivers_are_built_by_the_resolver(): void
    {
        $reports = new ArrayObject();
        $command = new EvaluationCommand(
            configLoader: $this->config(outputDrivers: [RecordingOutput::class]),
            discovery: $this->discovering(GreetingEvaluator::class),
            resolver: $this->containerResolver('Hello', $reports),
        );

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);
        $this->assertSame('Hello, Ada', $this->firstOutput($reports));
    }

    public function test_the_resolver_defaults_to_the_configured_one(): void
    {
        $reports = new ArrayObject();
        $command = new EvaluationCommand(
            configLoader: $this->config(
                outputDrivers: [RecordingOutput::class],
                resolver: $this->containerResolver('Hi', $reports),
            ),
            discovery: $this->discovering(GreetingEvaluator::class),
        );

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);
        $this->assertSame('Hi, Ada', $this->firstOutput($reports));
    }

    public function test_the_constructor_resolver_wins_over_the_configured_one(): void
    {
        $reports = new ArrayObject();
        $command = new EvaluationCommand(
            configLoader: $this->config(
                outputDrivers: [RecordingOutput::class],
                resolver: static fn (string $class): object => throw new LogicException('The configured resolver was used'),
            ),
            discovery: $this->discovering(GreetingEvaluator::class),
            resolver: $this->containerResolver('Hello', $reports),
        );

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);
        $this->assertSame('Hello, Ada', $this->firstOutput($reports));
    }

    public function test_without_a_resolver_an_evaluator_needing_arguments_points_to_one(): void
    {
        $command = new EvaluationCommand(
            configLoader: $this->config(),
            discovery: $this->discovering(GreetingEvaluator::class),
        );
        /** @var resource $errorStream */
        $errorStream = fopen('php://memory', 'r+');
        $command->setErrorStream($errorStream);

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub']);
        ob_end_clean();

        $this->assertSame(1, $exitCode);
        rewind($errorStream);
        $this->assertStringContainsString(
            GreetingEvaluator::class . ' requires constructor arguments: build it with a resolver',
            (string) stream_get_contents($errorStream)
        );
    }

    public function test_cache_options_apply_to_the_injected_runner(): void
    {
        $runner = $this->createMock(EvaluatorRunner::class);
        $runner->expects($this->once())
            ->method('withCache')
            ->with($this->isInstanceOf(FileEvaluationCache::class), true)
            ->willReturnSelf();
        $runner->expects($this->once())->method('run')->willReturn(new EvaluationResults([]));

        $command = new EvaluationCommand(
            configLoader: $this->config(),
            discovery: $this->discovering(RunCountingEvaluator::class),
            runner: $runner,
        );

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub', '--fresh']);
        ob_end_clean();

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

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Stub']);
        ob_end_clean();

        $this->assertSame(0, $exitCode);
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
     * @param array<int, string> $outputDrivers
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

    /**
     * @param ArrayObject<int, EvaluationReport> $reports
     */
    protected function firstOutput(ArrayObject $reports): mixed
    {
        $this->assertCount(1, $reports);

        return $reports[0]->getEvaluatorReports()[0]->getResults()->getResults()[0]->getOutput();
    }
}
