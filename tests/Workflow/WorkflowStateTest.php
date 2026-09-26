<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

class WorkflowStateTest extends TestCase
{
    public function test_constructor_data_is_readable(): void
    {
        $state = new WorkflowState(['customer' => 'alice', 'total' => 0]);

        $this->assertSame('alice', $state->get('customer'));
        $this->assertSame(0, $state->get('total'));
        $this->assertSame(['customer' => 'alice', 'total' => 0], $state->all());
    }

    public function test_set_overwrites_and_get_returns_the_latest_value(): void
    {
        $state = new WorkflowState();

        $state->set('step', 'first');
        $state->set('step', 'second');

        $this->assertSame('second', $state->get('step'));
        $this->assertSame(['step' => 'second'], $state->all());
    }

    public function test_get_returns_the_default_only_for_missing_keys(): void
    {
        $state = new WorkflowState(['zero' => 0, 'empty' => '', 'false' => false, 'list' => []]);

        $this->assertNull($state->get('missing'));
        $this->assertSame('fallback', $state->get('missing', 'fallback'));
        $this->assertSame(0, $state->get('zero', 'fallback'));
        $this->assertSame('', $state->get('empty', 'fallback'));
        $this->assertFalse($state->get('false', 'fallback'));
        $this->assertSame([], $state->get('list', 'fallback'));
    }

    public function test_has_distinguishes_a_stored_null_from_a_missing_key(): void
    {
        $state = new WorkflowState(['nothing' => null]);

        $this->assertTrue($state->has('nothing'));
        $this->assertFalse($state->has('missing'));
    }

    public function test_keys_are_exact_and_support_unicode(): void
    {
        $state = new WorkflowState();
        $state->set('città', 'Roma');
        $state->set('Città', 'Milano');
        $state->set(' città ', 'Napoli');

        $this->assertSame('Roma', $state->get('città'));
        $this->assertSame('Milano', $state->get('Città'));
        $this->assertSame('Napoli', $state->get(' città '));
        $this->assertCount(3, $state->all());
    }

    public function test_delete_removes_a_key_and_ignores_missing_ones(): void
    {
        $state = new WorkflowState(['keep' => 1, 'drop' => 2]);

        $state->delete('drop');
        $state->delete('missing');

        $this->assertFalse($state->has('drop'));
        $this->assertSame(['keep' => 1], $state->all());
    }

    public function test_only_returns_the_requested_existing_keys(): void
    {
        $state = new WorkflowState(['a' => 1, 'b' => null, 'c' => 3]);

        $this->assertSame(['a' => 1, 'b' => null], $state->only(['a', 'b', 'missing']));
        $this->assertSame([], $state->only([]));
    }

    public function test_except_returns_every_other_key(): void
    {
        $state = new WorkflowState(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['c' => 3], $state->except('a', 'b', 'missing'));
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $state->except());
    }

    public function test_a_new_state_is_running_without_identity_or_interruption(): void
    {
        $state = new WorkflowState();

        $this->assertSame(WorkflowStatus::Running, $state->getStatus());
        $this->assertFalse($state->isInterrupted());
        $this->assertNull($state->getInterruptRequest());
        $this->assertNull($state->getWorkflowId());
        $this->assertNull($state->getRunId());
        $this->assertNull($state->getExecutionAttempt());
    }

    public function test_status_transitions_keep_the_interruption_consistent(): void
    {
        $state = new WorkflowState();
        $request = new WaitForEventRequest('order.approved');

        $state->markAsSuspended($request);
        $this->assertSame(WorkflowStatus::Suspended, $state->getStatus());
        $this->assertTrue($state->isInterrupted());
        $this->assertSame($request, $state->getInterruptRequest());

        $state->markAsRunning();
        $this->assertSame(WorkflowStatus::Running, $state->getStatus());
        $this->assertFalse($state->isInterrupted());
        $this->assertNull($state->getInterruptRequest());

        $state->markAsSuspended($request);
        $state->clearInterrupt();
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertFalse($state->isInterrupted());
        $this->assertNull($state->getInterruptRequest());

        $state->markAsFailed();
        $this->assertSame(WorkflowStatus::Failed, $state->getStatus());
        $this->assertFalse($state->isInterrupted());
    }

    public function test_the_status_alone_decides_whether_the_state_is_interrupted(): void
    {
        // A persisted checkpoint is suspended without its request, which
        // lives in the run's control record instead.
        $checkpoint = new WorkflowState();
        $checkpoint->markAsSuspended(null);
        $this->assertTrue($checkpoint->isInterrupted());
        $this->assertNull($checkpoint->getInterruptRequest());

        $failed = new WorkflowState();
        $failed->markAsSuspended(new WaitForEventRequest('order.approved'));
        $failed->markAsFailed();
        $this->assertFalse($failed->isInterrupted());
    }

    public function test_execution_metadata_is_not_application_state(): void
    {
        $state = new WorkflowState(['customer' => 'alice']);

        $state->setExecutionMetadata('workflow-1', 'run-1', 2);

        $this->assertSame('workflow-1', $state->getWorkflowId());
        $this->assertSame('run-1', $state->getRunId());
        $this->assertSame(2, $state->getExecutionAttempt());
        $this->assertSame(['customer' => 'alice'], $state->all());
        $this->assertFalse($state->has('__workflowId'));
        $this->assertFalse($state->has('__runId'));
        $this->assertFalse($state->has('__executionAttempt'));
    }

    public function test_application_data_cannot_overwrite_execution_metadata(): void
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('workflow-1', 'run-1', 2);

        $state->set('workflowId', 'forged');
        $state->set('runId', 'forged');
        $state->set('executionAttempt', 99);

        $this->assertSame('workflow-1', $state->getWorkflowId());
        $this->assertSame('run-1', $state->getRunId());
        $this->assertSame(2, $state->getExecutionAttempt());
    }

    public function test_execution_metadata_survives_state_cloning(): void
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('workflow-1', 'run-1', 2);

        $clone = clone $state;

        $this->assertSame('workflow-1', $clone->getWorkflowId());
        $this->assertSame('run-1', $clone->getRunId());
        $this->assertSame(2, $clone->getExecutionAttempt());
    }

    public function test_a_clone_deeply_detaches_nested_objects(): void
    {
        $state = new WorkflowState(['order' => (object) ['items' => [(object) ['qty' => 1]]]]);

        $clone = clone $state;
        $clone->get('order')->items[0]->qty = 5;
        $clone->set('added', true);

        $this->assertSame(1, $state->get('order')->items[0]->qty);
        $this->assertFalse($state->has('added'));
    }

    public function test_serialization_round_trip_preserves_data_status_identity_and_interruption(): void
    {
        $state = new WorkflowState(['nested' => ['list' => [1, 2, 3]], 'text' => 'ünïcødé']);
        $state->setExecutionMetadata('workflow-1', 'run-1', 3);
        $state->markAsSuspended(new WaitForEventRequest('order.approved'));

        $restored = unserialize(serialize($state));

        $this->assertInstanceOf(WorkflowState::class, $restored);
        $this->assertSame($state->all(), $restored->all());
        $this->assertSame(WorkflowStatus::Suspended, $restored->getStatus());
        $this->assertSame('workflow-1', $restored->getWorkflowId());
        $this->assertSame('run-1', $restored->getRunId());
        $this->assertSame(3, $restored->getExecutionAttempt());
        $this->assertInstanceOf(WaitForEventRequest::class, $restored->getInterruptRequest());
        $this->assertSame('order.approved', $restored->getInterruptRequest()->getEventName());
    }
}
