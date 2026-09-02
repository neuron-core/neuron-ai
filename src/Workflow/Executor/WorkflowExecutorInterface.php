<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\WorkflowRuntimeInterface;
use NeuronAI\Workflow\WorkflowState;

/**
 * Executes a workflow run.
 *
 * Implementations define the execution model: sequential in-process,
 * concurrent branches with Amp fibers, or something else entirely. An
 * executor owns no configuration — it reads the run's full context
 * (definition, state store, and run identity) from the
 * WorkflowRuntimeInterface it is handed, so one executor strategy composes
 * with any persistence backend or external coordination platform.
 */
interface WorkflowExecutorInterface
{
    /**
     * Ignite a new run, yielding every event in real time and returning the
     * final state.
     *
     * The executor drives the full segment lifecycle: it resolves the
     * workflow ID, resolves ignition (register / adopt / refuse) and calls
     * the workflow's bootstrap() before traversal begins.
     *
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function execute(WorkflowRuntimeInterface $workflow): Generator;

    /**
     * Continue an existing run, optionally delivering addressed inputs.
     *
     * @param list<\NeuronAI\Workflow\Interrupt\ResumeInput> $inputs
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function resume(
        WorkflowRuntimeInterface $workflow,
        array $inputs = [],
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): Generator;

    /**
     * Conditionally remove a retained completed generation after its outcome
     * has been durably acknowledged by the caller/platform.
     */
    public function acknowledgeCompletion(
        WorkflowRuntimeInterface $workflow,
        string $expectedRunId,
    ): void;
}
