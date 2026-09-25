<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use Closure;
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
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function serialize;

class WorkflowEngineTest extends TestCase
{
    public function test_missing_run_inspection_only_reads_persistence(): void
    {
        $persistence = $this->createMock(PersistenceInterface::class);
        $persistence->expects(self::once())->method('get')->with('missing', '__control')->willReturn(null);
        $persistence->expects(self::never())->method('initializeIfAbsent');
        $persistence->expects(self::never())->method('writeIfUnchanged');
        $persistence->expects(self::never())->method('deleteIfUnchanged');

        self::assertNull((new WorkflowEngine($persistence))->inspect('missing'));
    }

    public function test_one_engine_reads_multiple_workflows_and_fresh_lifecycle_states(): void
    {
        $persistence = new InMemoryPersistence();
        $first = KeyedWorkflow::make('first')->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $second = KeyedWorkflow::make('second')->setPersistence($persistence);
        $firstState = $first->run();
        $secondState = $second->run();
        $engine = new WorkflowEngine($persistence);
        $before = serialize($persistence);

        $snapshot = $engine->inspect('first');
        self::assertNotNull($snapshot);
        self::assertSame('first', $snapshot->workflowId);
        self::assertSame($firstState->getRunId(), $snapshot->runId);
        self::assertSame($firstState->getExecutionAttempt(), $snapshot->executionAttempt);
        self::assertSame(WorkflowStatus::Suspended, $snapshot->status);
        self::assertEquals($firstState->getInterruptRequest(), $snapshot->interrupt);
        self::assertEquals($first->inspect(), $snapshot);
        self::assertSame($secondState->getRunId(), $engine->inspect('second')?->runId);
        self::assertSame($before, serialize($persistence));

        $first->run(ExecutionRequest::resume([], $snapshot->runId, $snapshot->executionAttempt));
        $completed = $engine->inspect('first');
        self::assertNotNull($completed);
        self::assertSame(WorkflowStatus::Completed, $completed->status);
        self::assertNull($completed->interrupt);
        self::assertSame(WorkflowStatus::Suspended, $snapshot->status);
        self::assertNotNull($snapshot->interrupt);

        $first->acknowledge($snapshot->runId);
        self::assertNull($engine->inspect('first'));
        self::assertSame(WorkflowStatus::Suspended, $engine->inspect('second')?->status);
        $second->run(ExecutionRequest::resume([]));
        self::assertNull($engine->inspect('second'));
    }

    public function test_explicit_serializer_is_used_by_the_engine_and_the_workflow(): void
    {
        $persistence = new InMemoryPersistence();
        $persistence->initializeIfAbsent('custom', '__control', 'custom-encoded-control', ['__ignition' => 'custom-encoded-ignition']);
        $serializer = $this->createMock(Serializer::class);
        $serializer->expects(self::never())->method('serialize');
        $serializer->expects(self::exactly(4))->method('unserialize')->willReturnMap([
            ['custom-encoded-control', new WorkflowControl('run', WorkflowStatus::Failed, executionAttempt: 3)],
            ['custom-encoded-ignition', new Ignition('run', new StartEvent())],
        ]);

        $snapshot = (new WorkflowEngine($persistence, $serializer))->inspect('custom');
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
        $engine = new WorkflowEngine($persistence);

        $snapshot = $engine->inspect('started');

        self::assertInstanceOf(StartEvent::class, $snapshot?->startEvent);
        self::assertNotSame($snapshot->startEvent, $engine->inspect('started')?->startEvent);
    }

    public function test_a_run_ending_between_the_two_reads_is_absent(): void
    {
        $persistence = $this->createMock(PersistenceInterface::class);
        $persistence->expects(self::exactly(3))->method('get')->willReturnOnConsecutiveCalls(
            serialize(new WorkflowControl('run_a', WorkflowStatus::Running)),
            null,
            null,
        );

        self::assertNull((new WorkflowEngine($persistence))->inspect('racing'));
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

        $snapshot = (new WorkflowEngine($persistence))->inspect('racing');

        self::assertSame('run_b', $snapshot?->runId);
        self::assertSame(WorkflowStatus::Suspended, $snapshot->status);
    }

    public function test_a_run_without_its_ignition_record_is_invalid(): void
    {
        $persistence = new InMemoryPersistence();
        $persistence->initializeIfAbsent('corrupt', '__control', serialize(new WorkflowControl('run_a', WorkflowStatus::Suspended)));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Run 'run_a' for workflow ID 'corrupt' has no ignition record.");
        (new WorkflowEngine($persistence))->inspect('corrupt');
    }

    public function test_invalid_control_type_is_not_reported_as_a_missing_run(): void
    {
        $persistence = new InMemoryPersistence();
        $persistence->initializeIfAbsent('corrupt', '__control', serialize(['status' => 'running']));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Invalid __control record');
        (new WorkflowEngine($persistence))->inspect('corrupt');
    }

    public function test_standalone_engine_abandons_and_acknowledges_runs_by_workflow_id(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = KeyedWorkflow::make('suspended')->setPersistence($persistence)->run();
        $retained = KeyedWorkflow::make('retained')->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $retained->run();
        $completed = $retained->run(ExecutionRequest::resume([]));
        $engine = new WorkflowEngine($persistence);

        self::assertTrue($engine->abandon('suspended', $suspended->getRunId(), $suspended->getExecutionAttempt()));
        self::assertNull($engine->inspect('suspended'));
        self::assertFalse($engine->abandon('suspended'));

        $engine->acknowledge('retained', (string) $completed->getRunId());
        self::assertNull($engine->inspect('retained'));
    }

    /** @return iterable<string, array{Closure(WorkflowEngine): void}> */
    public static function verbs(): iterable
    {
        yield 'inspect' => [static function (WorkflowEngine $engine): void {
            $engine->inspect('__control');
        }];
        yield 'abandon' => [static function (WorkflowEngine $engine): void {
            $engine->abandon('__control');
        }];
        yield 'acknowledge' => [static function (WorkflowEngine $engine): void {
            $engine->acknowledge('__control', 'run');
        }];
        yield 'admit' => [static function (WorkflowEngine $engine): void {
            $engine->admit('__control', ExecutionRequest::start(new StartEvent()), new WorkflowState(), null, false);
        }];
    }

    /** @param Closure(WorkflowEngine): void $verb */
    #[DataProvider('verbs')]
    public function test_every_verb_refuses_a_reserved_partition_before_reading_it(Closure $verb): void
    {
        $persistence = $this->createMock(PersistenceInterface::class);
        $persistence->expects(self::never())->method('get');

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Invalid workflow ID');
        $verb(new WorkflowEngine($persistence));
    }

    public function test_invalid_serialized_control_is_not_reported_as_a_missing_run(): void
    {
        $persistence = new InMemoryPersistence();
        $persistence->initializeIfAbsent('corrupt', '__control', 'invalid serialized bytes');

        $this->expectException(PersistenceException::class);
        (new WorkflowEngine($persistence))->inspect('corrupt');
    }
}
