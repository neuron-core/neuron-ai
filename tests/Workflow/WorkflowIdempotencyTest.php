<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\CrashAfterOperationClaim;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingNode;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function hash;
use function iterator_to_array;
use function time;

class WorkflowIdempotencyTest extends TestCase
{
    public function test_retained_completion_replays_and_cleanup_removes_the_key(): void
    {
        MemoizingNode::resetOperationCount();
        $persistence = new InMemoryPersistence();
        $make = static fn (): Workflow => Workflow::make('order')->setPersistence($persistence)
            ->addNode(new MemoizingNode())->retainCompletionUntilAcknowledged();
        $first = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'request-1', recoverFailed: true));
        $duplicate = $make()->events(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'request-1', recoverFailed: true));
        iterator_to_array($duplicate);
        self::assertEquals($first, $duplicate->getReturn());
        self::assertSame(1, MemoizingNode::getOperationCount());
        $make()->acknowledgeCompletion($first->getRunId());
        self::assertNull($persistence->get('order', '__operation/' . hash('sha256', 'request-1')));
        $next = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'request-1', recoverFailed: true));
        self::assertNotSame($first->getRunId(), $next->getRunId());
        self::assertSame(2, MemoizingNode::getOperationCount());
    }

    public function test_duplicate_start_and_resume_return_their_own_outcomes_after_the_run_advances(): void
    {
        $persistence = new InMemoryPersistence();
        $make = static fn (): KeyedWorkflow => KeyedWorkflow::make('order')->setPersistence($persistence)
            ->retainCompletionUntilAcknowledged();
        $start = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'start', recoverFailed: true));
        $completed = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], $start->getRunId(), $start->getExecutionAttempt(), idempotencyKey: 'answer'));
        self::assertSame(WorkflowStatus::Completed, $completed->getStatus());
        self::assertEquals($start, $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'start', recoverFailed: true)));
        self::assertEquals($completed, $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], $start->getRunId(), $start->getExecutionAttempt(), idempotencyKey: 'answer')));
        self::assertSame($completed->getExecutionAttempt(), $make()->inspect()->executionAttempt);
    }

    public function test_failed_operation_is_replayed_until_a_new_recovery_key_is_supplied(): void
    {
        MemoizingNode::resetOperationCount();
        $persistence = new InMemoryPersistence();
        $failed = Workflow::make('order')->setPersistence($persistence)->addNode(new MemoizingNode(true));
        try {
            $failed->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'start', recoverFailed: true));
            self::fail('Expected node failure.');
        } catch (RuntimeException $error) {
            self::assertSame('Simulated crash after memoize', $error->getMessage());
        }
        $retry = Workflow::make('order')->setPersistence($persistence)->addNode(new MemoizingNode());
        try {
            $retry->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'start', recoverFailed: true));
            self::fail('A duplicate must replay the failure.');
        } catch (WorkflowException $error) {
            self::assertStringContainsString('Recorded workflow operation failed', $error->getMessage());
        }
        self::assertSame(WorkflowStatus::Failed, $retry->inspect()->status);
        $failedRunId = $failed->inspect()->runId;
        $completed = $retry->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(idempotencyKey: 'retry'));
        self::assertSame($failedRunId, $completed->getRunId());
        self::assertSame(1, MemoizingNode::getOperationCount());
    }

    public function test_reusing_a_key_with_different_resume_input_is_rejected(): void
    {
        $persistence = new InMemoryPersistence();
        $make = static fn (): KeyedWorkflow => KeyedWorkflow::make('order')->setPersistence($persistence)
            ->retainCompletionUntilAcknowledged();
        $start = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'start', recoverFailed: true));
        $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], $start->getRunId(), idempotencyKey: 'answer'));
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('different workflow operation');
        $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['different' => true], $start->getRunId(), idempotencyKey: 'answer'));
    }

    public function test_reusing_a_key_for_start_and_resume_is_rejected(): void
    {
        $persistence = new InMemoryPersistence();
        KeyedWorkflow::make('order')->setPersistence($persistence)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'key', recoverFailed: true));
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('different workflow operation');
        KeyedWorkflow::make('order')->setPersistence($persistence)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], idempotencyKey: 'key'));
    }

    public function test_same_key_at_a_different_workflow_address_is_independent(): void
    {
        $persistence = new InMemoryPersistence();
        $first = KeyedWorkflow::make('one')->setPersistence($persistence)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'key', recoverFailed: true));
        $second = KeyedWorkflow::make('two')->setPersistence($persistence)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'key', recoverFailed: true));
        self::assertNotSame($first->getRunId(), $second->getRunId());
    }

    public function test_early_inputless_resume_is_a_recorded_no_op(): void
    {
        $persistence = new InMemoryPersistence();
        $make = static fn (): KeyedWorkflow => KeyedWorkflow::make('order')->setPersistence($persistence)
            ->retainCompletionUntilAcknowledged();
        $start = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'start', recoverFailed: true));
        $early = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(idempotencyKey: 'early'));
        self::assertSame($start->getExecutionAttempt(), $early->getExecutionAttempt());
        $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], idempotencyKey: 'answer'));
        self::assertEquals($early, $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(idempotencyKey: 'early')));
    }

    public function test_generated_identity_is_stable_for_repeated_idempotent_execution(): void
    {
        MemoizingNode::resetOperationCount();
        $workflow = Workflow::make()->addNode(new MemoizingNode())->retainCompletionUntilAcknowledged();
        $request = \NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'key');
        $first = $workflow->run($request);
        $second = $workflow->run($request);

        self::assertSame($first->getWorkflowId(), $workflow->getWorkflowId());
        self::assertSame($first->getRunId(), $second->getRunId());
        self::assertSame(1, MemoizingNode::getOperationCount());
    }

    public function test_an_empty_key_is_rejected_without_igniting_a_run(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make('order')->setPersistence($persistence)->addNode(new MemoizingNode());
        try {
            $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: '', recoverFailed: true));
            self::fail('Expected invalid key.');
        } catch (WorkflowException $error) {
            self::assertStringContainsString('must not be empty', $error->getMessage());
        }
        self::assertNull($workflow->inspect());
    }
    public function test_live_owner_is_refused_and_expired_owner_recovers_the_same_run(): void
    {
        $persistence = new InMemoryPersistence();
        $make = static fn (): Workflow => Workflow::make('order')->setPersistence($persistence)
            ->setLeaseTimeout(60)->addNode(new ChunkStreamingNode())->retainCompletionUntilAcknowledged();
        $old = $make()->events(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'key', recoverFailed: true));
        $old->rewind();
        $before = $make()->inspect();
        try {
            $make()->setLeaseTimeout(null)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'key', recoverFailed: true));
            self::fail('A live persisted lease must prevent recovery even when the retry disables leases.');
        } catch (WorkflowException $error) {
            self::assertStringContainsString('appears to be executing', $error->getMessage());
        }
        $serializer = new PhpSerializer();
        $raw = $persistence->get('order', '__control');
        $control = $serializer->unserialize($raw);
        self::assertInstanceOf(WorkflowControl::class, $control);
        self::assertTrue($persistence->writeIfUnchanged('order', '__control', $raw, [
            '__control' => $serializer->serialize($control->heartbeat(time() - 1)),
        ]));
        $recovered = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'key', recoverFailed: true));
        self::assertSame($before->runId, $recovered->getRunId());
        self::assertSame($before->executionAttempt + 1, $recovered->getExecutionAttempt());
        try {
            iterator_to_array($old);
            self::fail('The replaced worker must lose its write fence.');
        } catch (WorkflowException $error) {
            self::assertStringContainsString('Stale execution attempt', $error->getMessage());
        }
        self::assertEquals($recovered, $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'key', recoverFailed: true)));
    }

    public function test_a_different_key_cannot_adopt_an_existing_generation(): void
    {
        $persistence = new InMemoryPersistence();
        $make = static fn (): Workflow => Workflow::make('order')->setPersistence($persistence)
            ->addNode(new ChunkStreamingNode());
        $running = $make()->events(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'first', recoverFailed: true));
        $running->rewind();
        $run = $make()->inspect();
        try {
            $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'different', recoverFailed: true));
            self::fail('A different request must not adopt this run.');
        } catch (WorkflowException) {
            self::assertSame($run->runId, $make()->inspect()->runId);
        }
        unset($running);
    }

    public function test_resume_recovers_accepted_input_after_the_claim_acknowledgement_is_lost(): void
    {
        $persistence = new CrashAfterOperationClaim();
        $make = static fn (): KeyedWorkflow => KeyedWorkflow::make('order')->setPersistence($persistence)
            ->retainCompletionUntilAcknowledged();
        $started = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(idempotencyKey: 'start', recoverFailed: true));
        $persistence->crash = true;
        try {
            $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], $started->getRunId(), $started->getExecutionAttempt(), idempotencyKey: 'answer'));
            self::fail('Expected the injected crash.');
        } catch (RuntimeException $error) {
            self::assertSame('Lost claim acknowledgement', $error->getMessage());
        }
        self::assertSame(WorkflowStatus::Running, $make()->inspect()->status);
        $completed = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], $started->getRunId(), $started->getExecutionAttempt(), idempotencyKey: 'answer'));
        self::assertSame(WorkflowStatus::Completed, $completed->getStatus());
        self::assertSame($started->getRunId(), $completed->getRunId());
        self::assertSame(3, $completed->getExecutionAttempt());
        self::assertEquals($completed, $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], $started->getRunId(), $started->getExecutionAttempt(), idempotencyKey: 'answer')));
    }

}
