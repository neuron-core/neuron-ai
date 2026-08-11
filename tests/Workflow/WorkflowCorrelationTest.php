<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stubs\CorrelatedWorkflow;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

/**
 * The correlation pointer: a run declaring a business key becomes addressable
 * by that key, so a blank process holding only the key can continue it.
 *
 * The pointer row (partition '__correlation') records the MOST RECENT run
 * ignited for the key — a historical fact, never deleted. Liveness is derived
 * at lookup: the named run is in flight iff its ignition record still exists.
 */
class WorkflowCorrelationTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testIgnitionBindsThePointer(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = CorrelatedWorkflow::make()->withCorrelationKey('thread_1');

        $state = $this->execute($workflow, $persistence);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame($workflow->getRunId(), $persistence->get('__correlation', 'thread_1'));
    }

    public function testBlankInstanceContinuesByCorrelationKey(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = CorrelatedWorkflow::make()->withCorrelationKey('thread_1');
        $this->execute($suspended, $persistence);

        // The continuation holds the business key only — no runId anywhere.
        $resumed = CorrelatedWorkflow::make()->withCorrelationKey('thread_1');
        $state = $this->resume($resumed, $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
        $this->assertTrue($state->get('node_three_executed'));
        $this->assertSame($suspended->getRunId(), $resumed->getRunId());
    }

    public function testCompletedRunReadsAsNoRunInFlight(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = CorrelatedWorkflow::make()->withCorrelationKey('thread_1');
        $this->execute($suspended, $persistence);

        $this->resume(CorrelatedWorkflow::make()->withCorrelationKey('thread_1'), $persistence, []);

        // The pointer row survives completion as a historical fact ...
        $this->assertSame($suspended->getRunId(), $persistence->get('__correlation', 'thread_1'));

        // ... but the thread reads as free: the run's partition (and with it
        // the ignition record) was deleted, so a further thread-first
        // continuation has nothing to continue.
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight for correlation key 'thread_1'");

        $this->resume(CorrelatedWorkflow::make()->withCorrelationKey('thread_1'), $persistence, []);
    }

    public function testExplicitRunIdWinsOverThePointer(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = CorrelatedWorkflow::make(runId: 'run_explicit')->withCorrelationKey('thread_1');
        $this->execute($suspended, $persistence);

        // A later run claimed the thread's pointer (last-writer-wins).
        $persistence->put('__correlation', 'thread_1', 'run_someone_else');

        $resumed = CorrelatedWorkflow::make(runId: 'run_explicit')->withCorrelationKey('thread_1');
        $state = $this->resume($resumed, $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('run_explicit', $resumed->getRunId());
    }

    public function testContinuationWithoutRunIdOrCorrelationKeyThrows(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('declares no correlation key');

        $this->resume(
            Workflow::make()->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]),
            new InMemoryPersistence(),
            [],
        );
    }

    public function testContinuationWithNoPointerBoundThrows(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight for correlation key 'thread_unknown'");

        $this->resume(
            CorrelatedWorkflow::make()->withCorrelationKey('thread_unknown'),
            new InMemoryPersistence(),
            [],
        );
    }

    public function testStalePointerToACompletedExplicitRunReadsAsNoRunInFlight(): void
    {
        // A pointer whose run completed (partition deleted) must behave
        // exactly like no pointer at all — derived liveness, not row presence.
        $persistence = new InMemoryPersistence();
        $persistence->put('__correlation', 'thread_1', 'run_gone');

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight for correlation key 'thread_1'");

        $this->resume(
            CorrelatedWorkflow::make()->withCorrelationKey('thread_1'),
            $persistence,
            [],
        );
    }

    public function testSupersededRunCompletingLeavesTheNewPointerIntact(): void
    {
        $persistence = new InMemoryPersistence();

        $runA = CorrelatedWorkflow::make()->withCorrelationKey('thread_1');
        $this->execute($runA, $persistence);

        // A second run starts on the same thread and claims the pointer.
        $runB = CorrelatedWorkflow::make()->withCorrelationKey('thread_1');
        $this->execute($runB, $persistence);
        $this->assertSame($runB->getRunId(), $persistence->get('__correlation', 'thread_1'));

        // Completing the superseded run must not unaddress its successor:
        // nothing ever deletes a pointer, so run B stays findable and live.
        $this->resume(
            CorrelatedWorkflow::make(runId: $runA->getRunId())->withCorrelationKey('thread_1'),
            $persistence,
            [],
        );

        $this->assertSame($runB->getRunId(), $persistence->get('__correlation', 'thread_1'));

        $resumedB = CorrelatedWorkflow::make()->withCorrelationKey('thread_1');
        $state = $this->resume($resumedB, $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame($runB->getRunId(), $resumedB->getRunId());
    }

    public function testPlainWorkflowsAreUnaffected(): void
    {
        $persistence = new class () extends InMemoryPersistence {
            public int $pointerWrites = 0;

            public function put(string $partition, string $key, string $value): void
            {
                if ($partition === '__correlation') {
                    $this->pointerWrites++;
                }
                parent::put($partition, $key, $value);
            }
        };

        $workflow = Workflow::make()->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);
        $this->execute($workflow, $persistence);

        $resumed = Workflow::make(runId: $workflow->getRunId())
            ->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);
        $state = $this->resume($resumed, $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(0, $persistence->pointerWrites);
    }
}
