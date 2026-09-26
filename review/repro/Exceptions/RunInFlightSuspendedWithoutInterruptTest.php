<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Exceptions;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

class RunInFlightSuspendedWithoutInterruptTest extends TestCase
{
    public function test_a_suspended_generation_without_a_readable_interrupt_still_raises_the_domain_exception(): void
    {
        $exception = new RunInFlightException('thread-1', 'run-a', WorkflowStatus::Suspended, 2);

        $this->assertInstanceOf(WorkflowException::class, $exception);
        $this->assertStringStartsWith(
            "Cannot ignite a new run for workflow ID 'thread-1': run 'run-a' (attempt 2) is suspended",
            $exception->getMessage()
        );
        $this->assertStringContainsString('run(ExecutionRequest::resume($payload))', $exception->getMessage());
    }
}
