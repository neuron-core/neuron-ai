<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Interrupt;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InterruptEnvelopeIdTest extends TestCase
{
    /** @return array<string, array{mixed}> */
    public static function nonIntegerIds(): array
    {
        return ['numeric prefix' => ['7abc'], 'numeric string' => ['7'], 'boolean' => [true], 'fraction' => [2.9]];
    }

    #[DataProvider('nonIntegerIds')]
    public function test_wait_envelopes_reject_non_integer_ids(mixed $id): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('interruptId must be an integer');

        WaitForEventRequest::fromArray(['interruptId' => $id, 'eventName' => 'order.paid']);
    }

    #[DataProvider('nonIntegerIds')]
    public function test_sleep_envelopes_reject_non_integer_ids(mixed $id): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('interruptId must be an integer');

        SleepUntilRequest::fromArray(['interruptId' => $id, 'wakeAt' => '2026-07-20T15:30:00+00:00']);
    }

    public function test_integer_ids_round_trip(): void
    {
        $this->assertSame(7, WaitForEventRequest::fromArray(['interruptId' => 7, 'eventName' => 'order.paid'])->getId());
        $this->assertSame(7, SleepUntilRequest::fromArray(['interruptId' => 7, 'wakeAt' => '2026-07-20T15:30:00+00:00'])->getId());
    }
}
