<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stubs\MemoizingNode;
use NeuronAI\Tests\Workflow\Stubs\AddressedWorkflow;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_slice;
use function count;
use function end;
use function time;

/**
 * The execution lease (opt-in): a heartbeat record distinguishing "a process
 * is executing this run right now" from the silence of a violent crash.
 * Deliberate stops (suspend, caught failure, completion) always release it;
 * only a hard kill leaves it held, and a held lease expires by aging rather
 * than by anyone releasing it. It is a guess with a timeout, never mutual
 * exclusion.
 */
class WorkflowLeaseTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function leasedWorkflow(int $timeout): AddressedWorkflow
    {
        return AddressedWorkflow::make()
            ->withDeclaredAddress('thread_1')
            ->setLeaseTimeout($timeout);
    }

    /**
     * Run a leased workflow to its suspension and hand back the store.
     */
    protected function suspendLeased(): InMemoryPersistence
    {
        $persistence = new InMemoryPersistence();
        $this->execute($this->leasedWorkflow(300), $persistence);

        return $persistence;
    }

    public function testDisabledByDefaultWritesNoLease(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(AddressedWorkflow::make()->withDeclaredAddress('thread_1'), $persistence);

        $this->assertNull($persistence->get('thread_1', '__lease'));
    }

    public function testSuspensionReleasesTheLease(): void
    {
        $persistence = $this->suspendLeased();

        // Waiting is a deliberate stop: the run said so on its way out.
        $this->assertSame('released', $persistence->get('thread_1', '__lease'));
    }

    public function testResumeRightAfterSuspendIsNotBlocked(): void
    {
        $persistence = $this->suspendLeased();

        // A user answering within seconds must never be told "executing".
        $state = $this->resume($this->leasedWorkflow(300), $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
    }

    public function testFreshHeldLeaseRefusesTheResume(): void
    {
        $persistence = $this->suspendLeased();

        // Simulate a violent crash mid-execution: the lease is left held
        // with a recent heartbeat, as if the process died without a word.
        $persistence->put('thread_1', '__lease', (string) time());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("The run at address 'thread_1' appears to be executing");

        $this->resume($this->leasedWorkflow(300), $persistence, []);
    }

    public function testStaleHeldLeaseAllowsTheResume(): void
    {
        $persistence = $this->suspendLeased();

        // The same violent crash, discovered after the lease aged out.
        $persistence->put('thread_1', '__lease', (string) (time() - 301));

        $state = $this->resume($this->leasedWorkflow(300), $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
    }

    public function testCaughtFailureReleasesTheLease(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(address: 'thread_failing')
            ->addNodes([new MemoizingNode(shouldCrash: true)])
            ->setLeaseTimeout(300);

        try {
            $this->execute($workflow, $persistence);
            $this->fail('Expected the node failure to propagate.');
        } catch (RuntimeException) {
            // A caught crash writes its failed marker and lets go of the
            // lease — the run is immediately revivable, no timeout to wait.
            $this->assertSame('released', $persistence->get('thread_failing', '__lease'));
        }
    }

    public function testExecutionClaimsAndCompletionSweepsTheLease(): void
    {
        $persistence = $this->suspendLeased();

        // Continue to completion: the lease is claimed while executing and
        // vanishes with the partition on the clean sweep.
        $this->resume($this->leasedWorkflow(300), $persistence, []);

        $this->assertNull($persistence->get('thread_1', '__lease'));
    }

    public function testHeartbeatsAreWrittenPerStepAndReleasedOnSuspend(): void
    {
        $persistence = new class () extends InMemoryPersistence {
            /** @var string[] */
            public array $leaseWrites = [];

            public function put(string $partition, string $key, string $value): void
            {
                if ($key === '__lease') {
                    $this->leaseWrites[] = $value;
                }
                parent::put($partition, $key, $value);
            }
        };

        $this->execute($this->leasedWorkflow(300), $persistence);

        // Claimed at start, renewed per step visit, released at the suspend.
        $this->assertGreaterThanOrEqual(3, count($persistence->leaseWrites));
        $this->assertSame('released', end($persistence->leaseWrites));

        foreach (array_slice($persistence->leaseWrites, 0, -1) as $write) {
            $this->assertGreaterThan(0, (int) $write, "Heartbeat '{$write}' must be a timestamp.");
        }
    }
}
