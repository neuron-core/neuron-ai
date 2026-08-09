<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\WorkflowRuntimeInterface;
use NeuronAI\Workflow\WorkflowState;

/**
 * Executes a workflow graph.
 *
 * Implementations define the traversal strategy: sequential in-process,
 * concurrent with Amp fibers, or durable via platforms like Flowline.
 */
interface WorkflowExecutorInterface
{
    /**
     * Execute the workflow, yielding every event in real time and returning the
     * final state. The single entry: with no payload it starts/replays; with a
     * payload it resumes the interrupted step (firing the scheduler's onResume so
     * it can cancel the satisfied wakeup).
     *
     * The workflow arrives already bootstrapped (event-node map loaded and
     * validated) — implementations can query getEventNodeMap()/getNodeForEvent()
     * immediately.
     *
     * @param array<string, mixed>|null $payload Null to start/replay; the delivered payload to resume.
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function execute(
        WorkflowRuntimeInterface $workflow,
        ?array $payload = null,
        bool $timedOut = false,
    ): Generator;

    /**
     * The step engine driving this executor's replay — the authority on where
     * a run's durable records actually live. The workflow routes engine-level
     * reads/writes (the ignition record) through it rather than through its
     * own configured persistence, which a custom executor may not use.
     */
    public function getStepEngine(): StepEngineInterface;
}
