<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\Serializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInspector;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function serialize;

class WorkflowInspectorTest extends TestCase
{
    public function test_missing_run_inspection_only_reads_persistence(): void
    {
        $persistence = $this->createMock(PersistenceInterface::class);
        $persistence->expects(self::once())->method('get')->with('missing', '__control')->willReturn(null);
        $persistence->expects(self::never())->method('initializeIfAbsent');
        $persistence->expects(self::never())->method('writeIfUnchanged');
        $persistence->expects(self::never())->method('deleteIfUnchanged');

        self::assertNull((new WorkflowInspector($persistence))->inspect('missing'));
    }

    public function test_one_inspector_reads_multiple_workflows_and_fresh_lifecycle_states(): void
    {
        $persistence = new InMemoryPersistence();
        $first = KeyedWorkflow::make('first')->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $second = KeyedWorkflow::make('second')->setPersistence($persistence);
        $firstState = $first->run();
        $secondState = $second->run();
        $inspector = new WorkflowInspector($persistence);
        $before = serialize($persistence);

        $snapshot = $inspector->inspect('first');
        self::assertNotNull($snapshot);
        self::assertSame('first', $snapshot->workflowId);
        self::assertSame($firstState->getRunId(), $snapshot->runId);
        self::assertSame($firstState->getExecutionAttempt(), $snapshot->executionAttempt);
        self::assertSame(WorkflowStatus::Suspended, $snapshot->status);
        self::assertEquals($firstState->getInterruptRequest(), $snapshot->interrupt);
        self::assertEquals($first->inspect(), $snapshot);
        self::assertSame($secondState->getRunId(), $inspector->inspect('second')?->runId);
        self::assertSame($before, serialize($persistence));

        $first->run(ExecutionRequest::resume([], $snapshot->runId, $snapshot->executionAttempt));
        $completed = $inspector->inspect('first');
        self::assertNotNull($completed);
        self::assertSame(WorkflowStatus::Completed, $completed->status);
        self::assertNull($completed->interrupt);
        self::assertSame(WorkflowStatus::Suspended, $snapshot->status);
        self::assertNotNull($snapshot->interrupt);

        $first->acknowledgeCompletion($snapshot->runId);
        self::assertNull($inspector->inspect('first'));
        self::assertSame(WorkflowStatus::Suspended, $inspector->inspect('second')?->status);
        $second->run(ExecutionRequest::resume([]));
        self::assertNull($inspector->inspect('second'));
    }

    public function test_explicit_serializer_is_used_by_the_service_and_workflow_convenience_method(): void
    {
        $persistence = new InMemoryPersistence();
        $persistence->initializeIfAbsent('custom', '__control', 'custom-encoded-control', ['__ignition' => 'custom-encoded-ignition']);
        $serializer = $this->createMock(Serializer::class);
        $serializer->expects(self::never())->method('serialize');
        $serializer->expects(self::exactly(4))->method('unserialize')->willReturnMap([
            ['custom-encoded-control', new WorkflowControl('run', WorkflowStatus::Failed, executionAttempt: 3)],
            ['custom-encoded-ignition', new Ignition('run', new StartEvent())],
        ]);

        $snapshot = (new WorkflowInspector($persistence, $serializer))->inspect('custom');
        self::assertNotNull($snapshot);
        self::assertSame(WorkflowStatus::Failed, $snapshot->status);
        self::assertSame(3, $snapshot->executionAttempt);
        self::assertInstanceOf(StartEvent::class, $snapshot->startEvent);
        self::assertEquals($snapshot, Workflow::make('custom')->setPersistence($persistence)->setSerializer($serializer)->inspect());
    }

    public function test_each_snapshot_carries_its_own_copy_of_the_start_event(): void
    {
        $persistence = new InMemoryPersistence();
        KeyedWorkflow::make('started')->setPersistence($persistence)->run();
        $inspector = new WorkflowInspector($persistence);

        $snapshot = $inspector->inspect('started');

        self::assertInstanceOf(StartEvent::class, $snapshot?->startEvent);
        self::assertNotSame($snapshot->startEvent, $inspector->inspect('started')?->startEvent);
    }

    public function test_a_run_ending_between_the_two_reads_is_absent(): void
    {
        $persistence = $this->createMock(PersistenceInterface::class);
        $persistence->expects(self::exactly(3))->method('get')->willReturnOnConsecutiveCalls(
            serialize(new WorkflowControl('run_a', WorkflowStatus::Running)),
            null,
            null,
        );

        self::assertNull((new WorkflowInspector($persistence))->inspect('racing'));
    }

    public function test_a_run_replaced_between_the_two_reads_is_read_again(): void
    {
        $replacement = serialize(new WorkflowControl('run_b', WorkflowStatus::Suspended));
        $ignition = serialize(new Ignition('run_b', new StartEvent()));
        $persistence = $this->createMock(PersistenceInterface::class);
        $persistence->expects(self::exactly(4))->method('get')->willReturnOnConsecutiveCalls(
            serialize(new WorkflowControl('run_a', WorkflowStatus::Running)),
            $ignition,
            $replacement,
            $ignition,
        );

        $snapshot = (new WorkflowInspector($persistence))->inspect('racing');

        self::assertSame('run_b', $snapshot?->runId);
        self::assertSame(WorkflowStatus::Suspended, $snapshot->status);
    }

    public function test_a_run_without_its_ignition_record_is_invalid(): void
    {
        $persistence = new InMemoryPersistence();
        $persistence->initializeIfAbsent('corrupt', '__control', serialize(new WorkflowControl('run_a', WorkflowStatus::Suspended)));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Run 'run_a' for workflow ID 'corrupt' has no ignition record.");
        (new WorkflowInspector($persistence))->inspect('corrupt');
    }

    public function test_invalid_control_type_is_not_reported_as_a_missing_run(): void
    {
        $persistence = new InMemoryPersistence();
        $persistence->initializeIfAbsent('corrupt', '__control', serialize(['status' => 'running']));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Invalid __control record');
        (new WorkflowInspector($persistence))->inspect('corrupt');
    }

    public function test_invalid_serialized_control_is_not_reported_as_a_missing_run(): void
    {
        $persistence = new InMemoryPersistence();
        $persistence->initializeIfAbsent('corrupt', '__control', 'invalid serialized bytes');

        $this->expectException(PersistenceException::class);
        (new WorkflowInspector($persistence))->inspect('corrupt');
    }
}
