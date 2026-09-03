<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingNode;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function time;

/**
 * abandonRun() discards the generation holding a workflow ID, whatever it
 * waits for, behind the same fence as every other mutation. Only a retained
 * completion (acknowledge it instead) and a run under a fresh lease (a worker
 * is evidently executing it) refuse.
 */
class WorkflowAbandonTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function suspended(InMemoryPersistence $persistence): KeyedWorkflow
    {
        $workflow = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $this->execute($workflow, $persistence);

        return $workflow;
    }

    protected function crashed(InMemoryPersistence $persistence): Workflow
    {
        $workflow = Workflow::make('thread_1')->addNodes([new MemoizingNode(shouldCrash: true)]);
        try {
            $this->execute($workflow, $persistence);
            $this->fail('The workflow should fail.');
        } catch (RuntimeException) {
        }

        return $workflow;
    }

    protected function seedRunning(InMemoryPersistence $persistence, ?int $leaseExpiresAt): void
    {
        $serializer = new PhpSerializer();
        $persistence->initializeIfAbsent(
            'thread_1',
            '__control',
            $serializer->serialize(new WorkflowControl('run_stale', WorkflowStatus::Running, leaseExpiresAt: $leaseExpiresAt)),
            ['__ignition' => $serializer->serialize(new Ignition('run_stale', new StartEvent()))],
        );
    }

    protected function abandon(InMemoryPersistence $persistence, ?string $expectedRunId = null): bool
    {
        return KeyedWorkflow::make()
            ->withDeclaredWorkflowId('thread_1')
            ->setPersistence($persistence)
            ->abandonRun($expectedRunId);
    }

    public function test_abandon_discards_a_suspended_generation_and_frees_the_workflow_id(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = $this->suspended($persistence);

        $this->assertTrue($this->abandon($persistence));
        $this->assertNull($persistence->get('thread_1', '__control'));
        $this->assertNull($persistence->get('thread_1', '__ignition'));

        // The ID is free: a new generation ignites instead of being refused.
        $fresh = KeyedWorkflow::make()->withDeclaredWorkflowId('thread_1');
        $this->execute($fresh, $persistence);
        $this->assertNotSame($suspended->getRunId(), $fresh->getRunId());
    }

    public function test_abandon_discards_a_failed_generation(): void
    {
        $persistence = new InMemoryPersistence();
        $this->crashed($persistence);

        $this->assertTrue($this->abandon($persistence));
        $this->assertNull($persistence->get('thread_1', '__control'));
    }

    public function test_abandon_discards_an_unleased_running_generation(): void
    {
        $persistence = new InMemoryPersistence();
        $this->seedRunning($persistence, leaseExpiresAt: null);

        $this->assertTrue($this->abandon($persistence));
        $this->assertNull($persistence->get('thread_1', '__control'));
    }

    public function test_abandon_discards_a_running_generation_with_an_expired_lease(): void
    {
        $persistence = new InMemoryPersistence();
        $this->seedRunning($persistence, leaseExpiresAt: time() - 1);

        $this->assertTrue($this->abandon($persistence));
        $this->assertNull($persistence->get('thread_1', '__control'));
    }

    public function test_abandon_refuses_a_running_generation_with_a_fresh_lease(): void
    {
        $persistence = new InMemoryPersistence();
        $this->seedRunning($persistence, leaseExpiresAt: time() + 300);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Run 'run_stale' for workflow ID 'thread_1' appears to be executing");

        $this->abandon($persistence);
    }

    public function test_abandon_refuses_a_retained_completion(): void
    {
        $persistence = new InMemoryPersistence();
        $completed = Workflow::make('thread_1')
            ->setPersistence($persistence)
            ->retainCompletionUntilAcknowledged()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);
        $completed->run();

        try {
            $this->abandon($persistence);
            $this->fail('A retained completion should be acknowledged, not abandoned.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('acknowledgeCompletion()', $e->getMessage());
        }

        $this->assertNotNull($persistence->get('thread_1', '__control'));
    }

    public function test_abandon_reports_nothing_in_flight_as_false(): void
    {
        $this->assertFalse($this->abandon(new InMemoryPersistence()));
    }

    public function test_abandon_honours_a_matching_run_fence(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = $this->suspended($persistence);

        $this->assertTrue($this->abandon($persistence, (string) $suspended->getRunId()));
        $this->assertNull($persistence->get('thread_1', '__control'));
    }

    public function test_abandon_rejects_a_foreign_run_fence_without_mutation(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = $this->suspended($persistence);
        $control = $persistence->get('thread_1', '__control');

        try {
            $this->abandon($persistence, 'run_foreign');
            $this->fail('A stale generation should not be abandoned.');
        } catch (StaleWorkflowRunException $e) {
            $this->assertSame('run_foreign', $e->expectedRunId);
            $this->assertSame($suspended->getRunId(), $e->actualRunId);
        }

        $this->assertSame($control, $persistence->get('thread_1', '__control'));
    }

    public function test_abandon_reports_a_missing_expected_generation_as_stale(): void
    {
        try {
            $this->abandon(new InMemoryPersistence(), 'run_missing');
            $this->fail('A missing expected generation should be stale.');
        } catch (StaleWorkflowRunException $e) {
            $this->assertSame('run_missing', $e->expectedRunId);
            $this->assertNull($e->actualRunId);
        }
    }

    public function test_abandon_loses_to_a_concurrent_change(): void
    {
        $serializer = new PhpSerializer();
        $persistence = new class ($serializer) extends InMemoryPersistence {
            public function __construct(protected PhpSerializer $serializer)
            {
            }

            public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
            {
                // A recovery worker claims the generation between the read and the delete.
                $control = $this->serializer->unserialize($expectedValue);
                if ($control instanceof WorkflowControl) {
                    $this->storage[$partition][$conditionKey] = $this->serializer->serialize($control->claim(null));
                }

                return parent::deleteIfUnchanged($partition, $conditionKey, $expectedValue);
            }
        };
        $this->crashed($persistence);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Abandoning workflow ID 'thread_1' conflicted with a concurrent change");

        $this->abandon($persistence);
    }

    public function test_abandon_without_any_workflow_id_throws(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('the workflow declares none');

        Workflow::make()->setPersistence(new InMemoryPersistence())->abandonRun();
    }
}
