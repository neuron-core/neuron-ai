<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Exceptions;

use NeuronAI\Exceptions\StaleWorkflowRunException;
use PHPUnit\Framework\TestCase;

class StaleWorkflowRunExceptionTest extends TestCase
{
    public function test_names_the_expected_and_the_current_run(): void
    {
        $exception = new StaleWorkflowRunException('thread-1', 'run-a', 'run-b');

        $this->assertSame(
            "Stale continuation for workflow ID 'thread-1': expected run 'run-a', current run is 'run-b'.",
            $exception->getMessage()
        );
        $this->assertSame('thread-1', $exception->workflowId);
        $this->assertSame('run-a', $exception->expectedRunId);
        $this->assertSame('run-b', $exception->actualRunId);
    }

    public function test_reports_a_missing_current_run(): void
    {
        $exception = new StaleWorkflowRunException('thread-1', 'run-a', null);

        $this->assertSame(
            "Stale continuation for workflow ID 'thread-1': expected run 'run-a', current run is 'none'.",
            $exception->getMessage()
        );
        $this->assertNull($exception->actualRunId);
    }
}
