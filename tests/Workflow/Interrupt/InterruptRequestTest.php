<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Interrupt;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MIN;

class InterruptRequestTest extends TestCase
{
    public function test_an_unactivated_request_has_no_id(): void
    {
        $request = new WaitForEventRequest('order.paid');

        $this->assertNull($request->jsonSerialize()['interruptId']);
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('The interrupt request has not been activated yet.');

        $request->getId();
    }

    public function test_activation_returns_a_copy_and_leaves_the_original_unactivated(): void
    {
        $original = new WaitForEventRequest('order.paid');

        $activated = $original->withId(3);
        $reactivated = $activated->withId(4);

        $this->assertNotSame($original, $activated);
        $this->assertSame(3, $activated->getId());
        $this->assertSame(4, $reactivated->getId());
        $this->assertNull($original->jsonSerialize()['interruptId']);
    }

    #[DataProvider('nonPositiveIds')]
    public function test_activation_rejects_non_positive_ids(int $id): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('An interrupt ID must be a positive integer.');

        (new WaitForEventRequest('order.paid'))->withId($id);
    }

    /** @return array<string, array{int}> */
    public static function nonPositiveIds(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'minimum' => [PHP_INT_MIN]];
    }

    public function test_the_control_flow_signal_carries_the_request_and_its_message(): void
    {
        $request = new WaitForEventRequest('order.paid');

        $interrupt = new WorkflowInterrupt($request);

        $this->assertSame($request, $interrupt->getRequest());
        $this->assertSame("Waiting for event 'order.paid'", $interrupt->getMessage());
    }
}
