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
     * Execute the run, yielding every event in real time and returning the
     * final state. Intent is explicit: an ignition ($resuming false) refuses
     * a run already in flight under the workflow ID; a continuation
     * ($resuming true) adopts it, delivering the payload only when one is
     * given.
     *
     * The executor drives the full segment lifecycle: it resolves the
     * workflow ID, resolves ignition (register / adopt / refuse) and calls
     * the workflow's bootstrap() before traversal begins.
     *
     * @param list<\NeuronAI\Workflow\Resume\ResumeInput> $inputs Addressed inputs for a resume.
     * @param bool $resuming True to deliver resume inputs.
     * @param string|null $expectedRunId Optional generation fence supplied by a coordinator.
     * @param bool $recovering True to replay without delivering a new input.
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function execute(
        WorkflowRuntimeInterface $workflow,
        array $inputs = [],
        bool $resuming = false,
        ?string $expectedRunId = null,
        bool $recovering = false,
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
