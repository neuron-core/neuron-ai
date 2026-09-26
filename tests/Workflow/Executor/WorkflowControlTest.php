<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Workflow\Executor\ActiveInterrupt;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

/**
 * Control is the fence of every mutation: each transition must change only
 * what it names and leave the original value untouched.
 */
class WorkflowControlTest extends TestCase
{
    protected function waiting(int $id, string $stepId): ActiveInterrupt
    {
        return new ActiveInterrupt((new WaitForEventRequest("event-{$id}"))->withId($id), $stepId);
    }

    public function test_a_new_run_starts_at_the_first_attempt_and_interrupt_id(): void
    {
        $control = new WorkflowControl('run-1', WorkflowStatus::Running);

        $this->assertSame(1, $control->executionAttempt);
        $this->assertSame(1, $control->nextInterruptId);
        $this->assertNull($control->leaseExpiresAt);
        $this->assertNull($control->interrupt);
        $this->assertSame([], $control->pendingSteps);
    }

    public function test_a_claim_takes_the_next_attempt_and_its_lease(): void
    {
        $original = new WorkflowControl('run-1', WorkflowStatus::Suspended, executionAttempt: 4, nextInterruptId: 3);

        $claimed = $original->claim(1000);

        $this->assertSame(WorkflowStatus::Running, $claimed->status);
        $this->assertSame(5, $claimed->executionAttempt);
        $this->assertSame(1000, $claimed->leaseExpiresAt);
        $this->assertSame('run-1', $claimed->runId);
        $this->assertSame(3, $claimed->nextInterruptId);
        $this->assertSame(WorkflowStatus::Suspended, $original->status);
        $this->assertSame(4, $original->executionAttempt);
    }

    public function test_a_heartbeat_renews_only_the_lease(): void
    {
        $control = new WorkflowControl('run-1', WorkflowStatus::Running, executionAttempt: 2, leaseExpiresAt: 10);

        $renewed = $control->heartbeat(20);

        $this->assertSame(20, $renewed->leaseExpiresAt);
        $this->assertSame(2, $renewed->executionAttempt);
        $this->assertSame(WorkflowStatus::Running, $renewed->status);
        $this->assertSame(10, $control->leaseExpiresAt);
    }

    public function test_the_first_interruption_becomes_current(): void
    {
        $active = $this->waiting(1, 'step-a');

        $control = (new WorkflowControl('run-1', WorkflowStatus::Running))->addInterrupt($active);

        $this->assertSame($active, $control->interrupt);
        $this->assertSame([], $control->pendingSteps);
        $this->assertSame(2, $control->nextInterruptId);
    }

    public function test_later_interruptions_wait_in_arrival_order_behind_the_current_one(): void
    {
        $current = $this->waiting(1, 'step-a');

        $control = (new WorkflowControl('run-1', WorkflowStatus::Running))
            ->addInterrupt($current)
            ->addInterrupt($this->waiting(2, 'step-b'))
            ->addInterrupt($this->waiting(3, 'step-c'));

        $this->assertSame($current, $control->interrupt);
        $this->assertSame(['step-b', 'step-c'], $control->pendingSteps);
        $this->assertSame(4, $control->nextInterruptId);
    }

    public function test_resolving_the_current_interruption_promotes_the_oldest_deferred_one(): void
    {
        $promoted = $this->waiting(2, 'step-b');
        $control = (new WorkflowControl('run-1', WorkflowStatus::Running))
            ->addInterrupt($this->waiting(1, 'step-a'))
            ->addInterrupt($promoted)
            ->addInterrupt($this->waiting(3, 'step-c'));

        $next = $control->removeInterrupt($promoted);

        $this->assertSame($promoted, $next->interrupt);
        $this->assertSame(['step-c'], $next->pendingSteps);
        $this->assertSame(4, $next->nextInterruptId);
        $this->assertSame(['step-b', 'step-c'], $control->pendingSteps);
    }

    public function test_resolving_the_last_interruption_clears_it(): void
    {
        $control = (new WorkflowControl('run-1', WorkflowStatus::Running))
            ->addInterrupt($this->waiting(1, 'step-a'))
            ->removeInterrupt(null);

        $this->assertNull($control->interrupt);
        $this->assertSame([], $control->pendingSteps);
        $this->assertSame(2, $control->nextInterruptId);
    }

    public function test_accepting_input_keeps_the_current_interruption_and_the_queue(): void
    {
        $current = $this->waiting(1, 'step-a');
        $control = (new WorkflowControl('run-1', WorkflowStatus::Suspended))
            ->addInterrupt($current)
            ->addInterrupt($this->waiting(2, 'step-b'));

        $answered = $control->withInput(ResumeInput::event($current->request, ['ok' => true]));

        $this->assertSame($current->request, $answered->interrupt->request);
        $this->assertSame('step-a', $answered->interrupt->stepId);
        $this->assertSame(['ok' => true], $answered->interrupt->input->payload);
        $this->assertSame(['step-b'], $answered->pendingSteps);
        $this->assertNull($control->interrupt->input);
    }

    public function test_suspension_and_failure_clear_the_lease_but_keep_the_interruption(): void
    {
        $current = $this->waiting(1, 'step-a');
        $control = (new WorkflowControl('run-1', WorkflowStatus::Running, leaseExpiresAt: 99))
            ->addInterrupt($current)
            ->addInterrupt($this->waiting(2, 'step-b'));

        foreach ([[WorkflowStatus::Suspended, $control->suspended()], [WorkflowStatus::Failed, $control->failed()]] as [$status, $settled]) {
            $this->assertSame($status, $settled->status);
            $this->assertNull($settled->leaseExpiresAt);
            $this->assertSame($current, $settled->interrupt);
            $this->assertSame(['step-b'], $settled->pendingSteps);
            $this->assertSame(1, $settled->executionAttempt);
        }
    }

    public function test_completion_clears_the_lease_and_every_interruption(): void
    {
        $control = (new WorkflowControl('run-1', WorkflowStatus::Running, executionAttempt: 3, leaseExpiresAt: 99))
            ->addInterrupt($this->waiting(1, 'step-a'))
            ->addInterrupt($this->waiting(2, 'step-b'));

        $completed = $control->completed();

        $this->assertSame(WorkflowStatus::Completed, $completed->status);
        $this->assertNull($completed->leaseExpiresAt);
        $this->assertNull($completed->interrupt);
        $this->assertSame([], $completed->pendingSteps);
        $this->assertSame(3, $completed->executionAttempt);
        $this->assertSame(3, $completed->nextInterruptId);
    }
}
