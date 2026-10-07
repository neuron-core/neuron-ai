<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function serialize;
use function method_exists;
use function time;

class WorkflowInspectionTest extends TestCase
{
    public function test_inspection_of_a_missing_run_does_not_create_one(): void
    {
        $persistence = new InMemoryPersistence();
        $before = serialize($persistence);
        $this->assertNull(Workflow::make('test-workflow')->setPersistence($persistence)->inspect());
        $this->assertNull(Workflow::make('missing')->setPersistence($persistence)->inspect());
        $this->assertSame($before, serialize($persistence));
    }

    public function test_inspection_reads_the_suspended_generation_and_retained_completion(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = KeyedWorkflow::make()->withDeclaredWorkflowId('inspect')
            ->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $state = $workflow->run();
        $reader = Workflow::make('inspect')->setPersistence($persistence);
        $before = serialize($persistence);
        $run = $reader->inspect();
        $this->assertNotNull($run);
        $this->assertSame($state->getRunId(), $run->runId);
        $this->assertSame($state->getExecutionAttempt(), $run->executionAttempt);
        $this->assertSame(WorkflowStatus::Suspended, $run->status);
        $this->assertSame($before, serialize($persistence));
        $this->assertFalse(method_exists($reader, 'getRunId'));
        $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([], $run->runId, $run->executionAttempt));
        $completed = $reader->inspect();
        $this->assertNotNull($completed);
        $this->assertSame(WorkflowStatus::Completed, $completed->status);
        $this->assertNull($completed->interrupt);
        $this->assertSame(WorkflowStatus::Suspended, $run->status);
        $this->assertNotNull($run->interrupt);
        $workflow->acknowledge($run->runId);
        $this->assertNull($reader->inspect());
    }

    public function test_inspection_reports_the_lease_held_on_an_executing_run(): void
    {
        $persistence = new InMemoryPersistence();
        $seen = null;
        $probe = FakeMiddleware::make()->setBeforeHandler(static function () use ($persistence, &$seen): void {
            $seen = (new WorkflowEngine($persistence))->inspect('leased')?->leaseExpiresAt;
        });
        $workflow = KeyedWorkflow::make('leased')->setPersistence($persistence)
            ->setLeaseTimeout(300)->addMiddleware(NodeOne::class, $probe);
        $startedAt = time();

        $workflow->run();

        $this->assertGreaterThanOrEqual($startedAt + 300, $seen);
        $this->assertLessThanOrEqual(time() + 300, $seen);
        $suspended = $workflow->inspect();
        $this->assertSame(WorkflowStatus::Suspended, $suspended?->status);
        $this->assertNull($suspended->leaseExpiresAt);
    }

    public function test_inspection_rejects_conflicting_explicit_and_declared_workflow_ids(): void
    {
        $workflow = KeyedWorkflow::make('explicit')->withDeclaredWorkflowId('declared');
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Misidentified run');
        $workflow->inspect();
    }

}
