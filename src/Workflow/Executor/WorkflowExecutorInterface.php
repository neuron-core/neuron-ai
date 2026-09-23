<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowState;

/**
 * Executes a workflow run.
 *
 * Implementations define the execution model: sequential in-process,
 * concurrent branches with Amp fibers, or something else entirely. An
 * executor owns no configuration — it reads the run's full context
 * (definition, state store, and run identity) from the
 * Workflow it is handed, so one executor strategy composes
 * with any persistence backend or external coordination platform.
 */
interface WorkflowExecutorInterface
{
    /** Read the run's coordination state without claiming or executing it. */
    public function inspect(Workflow $workflow): ?WorkflowRunSnapshot;

    /**
     * Admit one request and execute its owned segment, or return its saved outcome.
     * Workflow binds its instance address before handing execution to this method.
     * @template TWorkflow of Workflow
     * @param TWorkflow $workflow
     * @return Generator<int, object, mixed, WorkflowState>
     */
    public function execute(Workflow $workflow, ExecutionRequest $request): Generator;

    /**
     * Conditionally remove a retained completed generation after its outcome
     * has been durably acknowledged by the caller/platform.
     */
    public function acknowledgeCompletion(
        Workflow $workflow,
        string $expectedRunId,
    ): void;

    /**
     * Conditionally discard the generation holding the workflow ID, whatever
     * it waits for, so the ID is free again. Refuses a retained completion
     * and a run under a fresh lease. False when nothing is in flight.
     */
    public function abandonRun(Workflow $workflow, ?string $expectedRunId = null, ?int $expectedExecutionAttempt = null): bool;
}
