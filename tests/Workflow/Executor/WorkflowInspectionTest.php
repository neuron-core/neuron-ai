<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function serialize;
use function method_exists;

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

    public function test_inspection_rejects_conflicting_explicit_and_declared_workflow_ids(): void
    {
        $workflow = KeyedWorkflow::make('explicit')->withDeclaredWorkflowId('declared');
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Misidentified run');
        $workflow->inspect();
    }

}
