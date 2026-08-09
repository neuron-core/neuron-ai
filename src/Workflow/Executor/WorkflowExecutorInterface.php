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
 * (definition, state store, scheduler, run id) from the
 * WorkflowRuntimeInterface it is handed, so one executor strategy composes
 * with any persistence backend or scheduler.
 */
interface WorkflowExecutorInterface
{
    /**
     * Execute the run, yielding every event in real time and returning the
     * final state. The single entry: with no payload it starts/replays; with a
     * payload it resumes the interrupted step (firing the scheduler's onResume
     * so it can cancel the satisfied wakeup).
     *
     * The executor drives the full segment lifecycle: it resolves ignition
     * (register / adopt / refuse) and calls the workflow's bootstrap() before
     * traversal begins.
     *
     * @param array<string, mixed>|null $payload Null to start/replay; the delivered payload to resume.
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function execute(
        WorkflowRuntimeInterface $workflow,
        ?array $payload = null,
        bool $timedOut = false,
    ): Generator;
}
