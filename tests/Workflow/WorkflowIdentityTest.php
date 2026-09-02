<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\ResumeInputStatus;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Key-based identity: a run's durable records live in the partition named
 * by its workflow ID (the declared business key, or a generated handle), with the
 * ignition record as the generation head. One live run per workflow ID, enforced
 * by refusal; completion sweeps the whole partition, leaving nothing behind.
 */
class WorkflowIdentityTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_records_live_under_the_declared_workflow_id(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');

        $state = $this->execute($workflow, $persistence);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('thread_1', $workflow->getWorkflowId());
        $this->assertNotNull($persistence->get('thread_1', '__ignition'));
        $this->assertNotNull($workflow->getRunId());
    }

    public function test_blank_instance_continues_by_workflow_id(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $this->execute($suspended, $persistence);

        // The continuation holds the business key only — no other handle.
        $resumed = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $state = $this->resume($resumed, $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
        $this->assertTrue($state->get('node_three_executed'));
        $this->assertSame($suspended->getRunId(), $resumed->getRunId());
    }

    public function test_stale_only_resume_does_not_claim_a_new_execution_attempt(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $first = $this->execute($workflow, $persistence);

        $duplicate = KeyedWorkflow::make()
            ->withDeclaredWorkflowId('thread_1')
            ->setPersistence($persistence)
            ->resume([ResumeInput::event(99, [])]);

        $this->assertSame($first->getExecutionAttempt(), $duplicate->getExecutionAttempt());
        $this->assertSame(ResumeInputStatus::Stale, $duplicate->getInputResults()[0]->status);
        $this->assertTrue($duplicate->isInterrupted());
        $this->assertTrue($duplicate->get('node_one_executed'));
        $this->assertTrue($duplicate->get('interruptable_node_executed'));
    }

    public function test_stale_only_resume_preserves_a_failed_status(): void
    {
        $persistence = new InMemoryPersistence();
        $crashingNode = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                throw new RuntimeException('crash');
            }
        };

        try {
            Workflow::make('failed-run')->setPersistence($persistence)->addNode($crashingNode)->run();
            $this->fail('The workflow should fail.');
        } catch (RuntimeException) {
        }

        $result = Workflow::make('failed-run')
            ->setPersistence($persistence)
            ->addNode(new NodeOne())
            ->resume([ResumeInput::event(99, [])]);

        $this->assertSame(WorkflowStatus::Failed, $result->getStatus());
        $this->assertSame(ResumeInputStatus::Stale, $result->getInputResults()[0]->status);
    }

    public function test_matching_run_fence_continues_the_expected_generation(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $this->execute($suspended, $persistence);

        $resumed = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $state = $this->resume(
            $resumed,
            $persistence,
            [],
            expectedRunId: $suspended->getRunId(),
        );

        $this->assertFalse($state->isInterrupted());
        $this->assertSame($suspended->getRunId(), $resumed->getRunId());
    }

    public function test_foreign_run_fence_is_rejected_without_mutation(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $this->execute($suspended, $persistence);
        $ignition = $persistence->get('thread_1', '__ignition');
        $control = $persistence->get('thread_1', '__control');

        try {
            $this->resume(
                KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'),
                $persistence,
                [],
                expectedRunId: 'run_foreign',
            );
            $this->fail('A stale generation should not be resumed.');
        } catch (StaleWorkflowRunException $e) {
            $this->assertSame('thread_1', $e->workflowId);
            $this->assertSame('run_foreign', $e->expectedRunId);
            $this->assertSame($suspended->getRunId(), $e->actualRunId);
        }

        $this->assertSame($ignition, $persistence->get('thread_1', '__ignition'));
        $this->assertSame($control, $persistence->get('thread_1', '__control'));
    }

    public function test_missing_expected_generation_is_reported_as_stale(): void
    {
        try {
            $this->resume(
                KeyedWorkflow::make()->withDeclaredWorkflowId('thread_missing'),
                new InMemoryPersistence(),
                [],
                expectedRunId: 'run_missing',
            );
            $this->fail('A missing expected generation should be stale.');
        } catch (StaleWorkflowRunException $e) {
            $this->assertSame('thread_missing', $e->workflowId);
            $this->assertSame('run_missing', $e->expectedRunId);
            $this->assertNull($e->actualRunId);
        }
    }

    public function test_ignition_refuses_a_live_workflow_id(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("A run is already in flight for workflow ID 'thread_1'");

        $this->execute(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence);
    }

    public function test_completion_sweeps_the_partition_and_frees_the_workflow_id(): void
    {
        $persistence = new InMemoryPersistence();
        $first = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $this->execute($first, $persistence);
        $this->resume(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence, []);

        // Nothing survives completion — no ignition, no orphan of any kind.
        $this->assertNull($persistence->get('thread_1', '__ignition'));

        // The workflow ID is free: a new run ignites with a fresh generation.
        $second = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $state = $this->execute($second, $persistence);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('thread_1', $second->getWorkflowId());
        $this->assertNotSame($first->getRunId(), $second->getRunId());
    }

    public function test_retained_completion_is_replayable_until_acknowledged(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make('retained-completion')
            ->setPersistence($persistence)
            ->retainCompletionUntilAcknowledged()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $completed = $workflow->run();
        $runId = (string) $workflow->getRunId();

        $this->assertSame(WorkflowStatus::Completed, $completed->getStatus());
        $this->assertNotNull($persistence->get('retained-completion', '__control'));
        $this->assertNotNull($persistence->get('retained-completion', '__ignition'));

        $retry = Workflow::make('retained-completion')->setPersistence($persistence);
        $replayed = $retry->resume(expectedRunId: $runId);

        $this->assertSame($completed->all(), $replayed->all());
        $retry->acknowledgeCompletion($runId);
        $this->assertNull($persistence->get('retained-completion', '__control'));
        $this->assertNull($persistence->get('retained-completion', '__ignition'));
    }

    public function test_resume_on_a_completed_workflow_id_throws(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence);
        $this->resume(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence, []);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight for workflow ID 'thread_1'");

        $this->resume(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence, []);
    }

    public function test_resume_on_an_unknown_workflow_id_throws(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight for workflow ID 'thread_unknown'");

        $this->resume(
            KeyedWorkflow::make()->withDeclaredWorkflowId('thread_unknown'),
            new InMemoryPersistence(),
            [],
        );
    }

    public function test_continuation_without_any_workflow_id_throws(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('the workflow declares none');

        $this->resume(
            Workflow::make()->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]),
            new InMemoryPersistence(),
            [],
        );
    }

    public function test_declared_and_explicit_workflow_id_conflict_throws(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Misidentified run: the workflow declares workflow ID 'thread_1' but was given 'thread_2'");

        $this->execute(
            KeyedWorkflow::make(workflowId: 'thread_2')->withDeclaredWorkflowId('thread_1'),
            new InMemoryPersistence(),
        );
    }

    public function test_reserved_and_empty_workflow_ids_throw(): void
    {
        foreach (['__reserved', ''] as $invalid) {
            try {
                $this->execute(
                    KeyedWorkflow::make()->withDeclaredWorkflowId($invalid),
                    new InMemoryPersistence(),
                );
                $this->fail("Workflow ID '{$invalid}' should have been rejected.");
            } catch (WorkflowException $e) {
                $this->assertStringContainsString('Invalid workflow ID', $e->getMessage());
            }
        }
    }

    public function test_bare_resume_revives_without_delivering_an_answer(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence);

        // Null payload: the suspended step re-runs, receives nothing, and
        // re-emits its request — the run is still waiting, which is the truth.
        $revived = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $state = $this->resume($revived, $persistence, null);

        $this->assertTrue($state->isInterrupted());
        $this->assertInstanceOf(InterruptRequest::class, $state->getInterruptRequest());
        $this->assertNull($state->get('received_feedback'));

        // The run stays continuable: an actual answer completes it.
        $state = $this->resume(
            KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'),
            $persistence,
            [],
        );
        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
    }

    public function test_empty_array_delivers_an_empty_answer(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence);

        // [] is not null: it reaches the waiting step as a delivered answer.
        $state = $this->resume(
            KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'),
            $persistence,
            [],
        );

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
    }

    public function test_generated_workflow_id_is_the_continuation_handle(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make()->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);
        $this->execute($workflow, $persistence);

        $workflowId = $workflow->getWorkflowId();
        $this->assertNotNull($workflowId);

        $resumed = Workflow::make($workflowId)
            ->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);
        $state = $this->resume($resumed, $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame($workflowId, $resumed->getWorkflowId());
    }

    public function test_mismatched_control_and_ignition_generations_are_rejected(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $this->execute($suspended, $persistence);

        // Corrupt the immutable envelope without changing the authoritative
        // control record. Normal commits create both together atomically.
        $expected = $persistence->get('thread_1', '__control');
        $this->assertNotNull($expected);
        $persistence->writeIfUnchanged(
            'thread_1',
            '__control',
            $expected,
            ['__ignition' => (new PhpSerializer())->serialize(new Ignition('run_foreign', new StartEvent()))],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('mismatched __control and __ignition generations');

        $this->resume(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence, []);
    }

    public function test_stale_owner_cannot_write_or_sweep_a_successor(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();

        // The node simulates a takeover happening mid-run: by the time this
        // run completes, the generation head names someone else.
        $hijacked = new class ($persistence, $serializer) extends Node {
            public function __construct(
                protected InMemoryPersistence $persistence,
                protected PhpSerializer $serializer,
            ) {
            }

            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $expected = $this->persistence->get('thread_1', '__control');
                if ($expected === null) {
                    throw new WorkflowException('The simulated owner has no control record.');
                }

                $this->persistence->writeIfUnchanged(
                    'thread_1',
                    '__control',
                    $expected,
                    [
                        '__ignition' => $this->serializer->serialize(new Ignition('run_successor', new StartEvent())),
                        '__control' => $this->serializer->serialize(new WorkflowControl(
                            'run_successor',
                            WorkflowStatus::Running,
                        )),
                    ],
                );

                return new StopEvent();
            }
        };

        $workflow = Workflow::make('thread_1')->addNode($hijacked);
        try {
            $this->execute($workflow, $persistence);
            $this->fail('The stale owner should lose its conditional step write.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('Stale execution attempt', $e->getMessage());
        }

        // The fenced sweep held: the successor's head record survives, the
        // zombie's own step record is left as inert garbage, and the
        // successor's coordination state was not dropped.
        $head = $serializer->unserialize((string) $persistence->get('thread_1', '__ignition'));
        $this->assertInstanceOf(Ignition::class, $head);
        $this->assertSame('run_successor', $head->runId);
        $this->assertNull($persistence->get('thread_1', $this->stepKey($workflow, $hijacked::class . '-0')));
    }

    public function test_state_carries_execution_metadata_outside_application_data(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');

        $state = $this->execute($workflow, $persistence);

        $this->assertFalse($state->has('__workflowId'));
        $this->assertFalse($state->has('__runId'));
        $this->assertFalse($state->has('__executionAttempt'));
        $this->assertSame('thread_1', $state->getWorkflowId());
        $this->assertSame($workflow->getRunId(), $state->getRunId());
        $this->assertSame(1, $state->getExecutionAttempt());
    }
}
