<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Interrupt;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;

class SleepEnvelopeWakeTimeTest extends TestCase
{
    public function test_a_sleep_envelope_without_wake_time_is_rejected(): void
    {
        $this->expectException(WorkflowException::class);

        SleepUntilRequest::fromArray(['interruptId' => 1]);
    }

    public function test_an_unparseable_wake_time_is_a_workflow_error(): void
    {
        $this->expectException(WorkflowException::class);

        SleepUntilRequest::fromArray(['interruptId' => 1, 'wakeAt' => 'not a date']);
    }

    public function test_a_relative_wake_time_is_rejected(): void
    {
        $this->expectException(WorkflowException::class);

        SleepUntilRequest::fromArray(['interruptId' => 1, 'wakeAt' => 'yesterday']);
    }

    public function test_a_wait_envelope_without_event_name_is_rejected(): void
    {
        $this->expectException(WorkflowException::class);

        WaitForEventRequest::fromArray(['interruptId' => 1]);
    }

    public function test_an_unparseable_deadline_is_a_workflow_error(): void
    {
        $this->expectException(WorkflowException::class);

        WaitForEventRequest::fromArray(['interruptId' => 1, 'eventName' => 'x', 'expiresAt' => 'not a date']);
    }
}
