<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Console;

use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Tests\Evaluation\Console\Fixtures\RunCountingEvaluator;
use PHPUnit\Framework\TestCase;

use function ob_end_clean;
use function ob_start;

class EvaluationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        RunCountingEvaluator::$runCount = 0;
    }

    public function testEachDatasetItemRunsExactlyOnce(): void
    {
        $command = new EvaluationCommand();

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Fixtures']);
        ob_end_clean();

        $this->assertEquals(0, $exitCode);

        // The fixture dataset has 2 items: run() must be invoked once per item,
        // not twice (the command used to re-run all evaluators to build the overall summary)
        $this->assertEquals(2, RunCountingEvaluator::$runCount);
    }

    public function testAcceptsConcurrencyOption(): void
    {
        $command = new EvaluationCommand();

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Fixtures', '--concurrency=2']);
        ob_end_clean();

        $this->assertEquals(0, $exitCode);
    }

    public function testRejectsInvalidConcurrency(): void
    {
        $command = new EvaluationCommand();

        ob_start();
        $exitCode = $command->run(['evaluation', __DIR__ . '/Fixtures', '--concurrency=0']);
        ob_end_clean();

        // The rejection message goes to STDERR; the exit code is the observable contract here
        $this->assertEquals(1, $exitCode);
    }
}
