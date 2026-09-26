<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\BranchPausedEvent;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

class StepResultTest extends TestCase
{
    public function test_a_completed_step_is_not_interrupted(): void
    {
        $step = new StepResult('step-1', new StopEvent('done'), new WorkflowState(['n' => 1]));

        $this->assertFalse($step->isInterrupted());
        $this->assertNull($step->getInterruptId());
        $this->assertSame('step-1', $step->getStepId());
    }

    public function test_a_step_paused_behind_another_interruption_is_not_a_marker(): void
    {
        $step = new StepResult('step-1', new BranchPausedEvent(), new WorkflowState());

        $this->assertFalse($step->isInterrupted());
        $this->assertNull($step->getInterruptId());
    }

    public function test_an_interruption_marker_exposes_its_request_id_and_state(): void
    {
        $request = (new WaitForEventRequest('order.paid'))->withId(3);
        $step = new StepResult('step-1', InterruptEvent::fromRequest($request), new WorkflowState(['n' => 1]));

        $this->assertTrue($step->isInterrupted());
        $this->assertSame(3, $step->getInterruptId());
        $this->assertSame(1, $step->getState()->get('n'));
    }

    public function test_a_step_without_event_refuses_to_route(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Step step-1 carries no event.');

        (new StepResult('step-1'))->getEvent();
    }

    public function test_a_step_without_state_refuses_to_replay(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Step step-1 carries no state.');

        (new StepResult('step-1', new StopEvent()))->getState();
    }

    public function test_a_persisted_interruption_marker_round_trips(): void
    {
        $serializer = new PhpSerializer();
        $request = (new WaitForEventRequest('order.paid'))->withId(5);
        $step = new StepResult('step-1', InterruptEvent::fromRequest($request), new WorkflowState(['n' => 1]));

        $restored = $serializer->unserialize($serializer->serialize($step));

        $this->assertInstanceOf(StepResult::class, $restored);
        $this->assertSame('step-1', $restored->getStepId());
        $this->assertSame(5, $restored->getInterruptId());
        $event = $restored->getEvent();
        $this->assertInstanceOf(InterruptEvent::class, $event);
        $this->assertInstanceOf(WaitForEventRequest::class, $event->request);
        $this->assertSame('order.paid', $event->request->getEventName());
        $this->assertSame(['n' => 1], $restored->getState()->all());
    }

    public function test_a_record_missing_its_optional_parts_restores_them_as_absent(): void
    {
        $restored = unserialize(serialize(new StepResult('step-1')));

        $this->assertInstanceOf(StepResult::class, $restored);
        $this->assertFalse($restored->isInterrupted());
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Step step-1 carries no event.');

        $restored->getEvent();
    }
}
