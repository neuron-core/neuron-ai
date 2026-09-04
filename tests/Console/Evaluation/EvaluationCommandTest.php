<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console\Evaluation;

use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Tests\Console\Evaluation\Stub\RunCountingEvaluator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function fopen;
use function ob_end_clean;
use function ob_start;
use function rewind;
use function stream_get_contents;

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
}
