<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

class WorkflowInspectionTest extends TestCase
{
    public function test_inspection_of_a_missing_run_does_not_create_one(): void
    {
        $persistence = new InMemoryPersistence();
        $before = serialize($persistence);
        $this->assertNull((new WorkflowExecutor())->inspect(Workflow::make()->setPersistence($persistence)));
        $this->assertNull((new WorkflowExecutor())->inspect(Workflow::make('missing')->setPersistence($persistence)));
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
        $run = (new WorkflowExecutor())->inspect($reader);
        $this->assertNotNull($run);
        $this->assertSame($state->getRunId(), $run->runId);
        $this->assertSame($state->getExecutionAttempt(), $run->executionAttempt);
        $this->assertSame(WorkflowStatus::Suspended, $run->status);
        $this->assertSame($before, serialize($persistence));
        $this->assertNull($reader->getRunId());

        $request = array_values($run->interrupts)[0];
        $workflow->resume([ResumeInput::event($request, [])], $run->runId, $run->executionAttempt)->run();
        $completed = (new WorkflowExecutor())->inspect($reader);
        $this->assertNotNull($completed);
        $this->assertSame(WorkflowStatus::Completed, $completed->status);
        $this->assertSame([], $completed->interrupts);
        $this->assertSame(WorkflowStatus::Suspended, $run->status);
        $this->assertCount(1, $run->interrupts);
        $workflow->acknowledgeCompletion($run->runId);
        $this->assertNull((new WorkflowExecutor())->inspect($reader));
    }

    public function test_inspection_rejects_conflicting_explicit_and_declared_workflow_ids(): void
    {
        $workflow = KeyedWorkflow::make('explicit')->withDeclaredWorkflowId('declared');
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Misidentified run');
        (new WorkflowExecutor())->inspect($workflow);
    }

}
