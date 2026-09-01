<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingNode;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
use function count;
use function time;

/**
 * The optional lease is part of the fenced __control record. It prevents a
 * recovery worker from claiming a run that still appears to be executing;
 * suspension and caught failure clear the deadline.
 */
class WorkflowLeaseTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function leasedWorkflow(int $timeout): KeyedWorkflow
    {
        return KeyedWorkflow::make()
            ->withDeclaredWorkflowId('thread_1')
            ->setLeaseTimeout($timeout);
    }

    protected function suspendLeased(): InMemoryPersistence
    {
        $persistence = new InMemoryPersistence();
        $this->execute($this->leasedWorkflow(300), $persistence);

        return $persistence;
    }

    protected function control(InMemoryPersistence $persistence, string $workflowId = 'thread_1'): WorkflowControl
    {
        $raw = $persistence->get($workflowId, '__control');
        $control = $raw === null ? null : (new PhpSerializer())->unserialize($raw);
        $this->assertInstanceOf(WorkflowControl::class, $control);

        return $control;
    }

    public function test_lease_is_disabled_by_default(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1'), $persistence);

        $this->assertNull($this->control($persistence)->leaseExpiresAt);
    }

    public function test_suspension_clears_the_lease_deadline(): void
    {
        $control = $this->control($this->suspendLeased());

        $this->assertSame(WorkflowStatus::Suspended, $control->status);
        $this->assertNull($control->leaseExpiresAt);
    }

    public function test_resume_right_after_suspend_is_not_blocked(): void
    {
        $persistence = $this->suspendLeased();

        $state = $this->resume($this->leasedWorkflow(300), $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
    }

    public function test_fresh_running_lease_refuses_recovery(): void
    {
        $persistence = $this->suspendLeased();
        $serializer = new PhpSerializer();
        $expected = $persistence->get('thread_1', '__control');
        $this->assertNotNull($expected);
        $running = $this->control($persistence)->claim(time() + 300);
        $persistence->writeIfUnchanged('thread_1', '__control', $expected, [
            '__control' => $serializer->serialize($running),
        ]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("The run for workflow ID 'thread_1' appears to be executing");

        $this->leasedWorkflow(300)->setPersistence($persistence)->resume();
    }

    public function test_expired_running_lease_allows_recovery_claim(): void
    {
        $persistence = $this->suspendLeased();
        $serializer = new PhpSerializer();
        $expected = $persistence->get('thread_1', '__control');
        $this->assertNotNull($expected);
        $running = $this->control($persistence)->claim(time() - 1);
        $persistence->writeIfUnchanged('thread_1', '__control', $expected, [
            '__control' => $serializer->serialize($running),
        ]);

        $state = $this->leasedWorkflow(300)->setPersistence($persistence)->resume();

        $this->assertTrue($state->isInterrupted());
        $this->assertGreaterThan($running->executionAttempt, $this->control($persistence)->executionAttempt);
    }

    public function test_stale_execution_attempt_refuses_continuation(): void
    {
        $persistence = $this->suspendLeased();
        $attempt = $this->control($persistence)->executionAttempt;

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Stale continuation for workflow ID 'thread_1'");

        $this->leasedWorkflow(300)
            ->setPersistence($persistence)
            ->resume(expectedExecutionAttempt: $attempt - 1);
    }

    public function test_caught_failure_clears_the_lease_deadline(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make(workflowId: 'thread_failing')
            ->addNodes([new MemoizingNode(shouldCrash: true)])
            ->setLeaseTimeout(300);

        try {
            $this->execute($workflow, $persistence);
            $this->fail('Expected the node failure to propagate.');
        } catch (RuntimeException) {
            $control = $this->control($persistence, 'thread_failing');
            $this->assertSame(WorkflowStatus::Failed, $control->status);
            $this->assertNull($control->leaseExpiresAt);
        }
    }

    public function test_completion_sweeps_control_with_the_partition(): void
    {
        $persistence = $this->suspendLeased();

        $this->resume($this->leasedWorkflow(300), $persistence, []);

        $this->assertNull($persistence->get('thread_1', '__control'));
    }

    public function test_control_heartbeats_are_committed_at_step_boundaries(): void
    {
        $serializer = new PhpSerializer();
        $persistence = new class ($serializer) extends InMemoryPersistence {
            /** @var WorkflowControl[] */
            public array $controls = [];

            public function __construct(protected PhpSerializer $serializer)
            {
            }

            public function writeIfUnchanged(
                string $partition,
                string $conditionKey,
                string $expectedValue,
                array $records,
            ): bool {
                $committed = parent::writeIfUnchanged(
                    $partition,
                    $conditionKey,
                    $expectedValue,
                    $records,
                );

                if ($committed && isset($records['__control'])) {
                    $control = $this->serializer->unserialize($records['__control']);
                    if ($control instanceof WorkflowControl) {
                        $this->controls[] = $control;
                    }
                }

                return $committed;
            }
        };

        $this->execute($this->leasedWorkflow(300), $persistence);

        $runningWithLease = array_filter(
            $persistence->controls,
            fn (WorkflowControl $control): bool => $control->status === WorkflowStatus::Running
                && $control->leaseExpiresAt !== null,
        );

        $this->assertGreaterThanOrEqual(3, count($runningWithLease));
        $this->assertSame(WorkflowStatus::Suspended, $this->control($persistence)->status);
        $this->assertNull($this->control($persistence)->leaseExpiresAt);
    }
}
