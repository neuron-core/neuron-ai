<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowInterface;
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
     * Execute the workflow from start to finish.
     *
     * Yields every event from every node in real time.
     * Returns the final WorkflowState.
     *
     * @param InterruptRequest|null $interrupt User decision when resuming from an interrupt
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function execute(
        WorkflowInterface $workflow,
        ?InterruptRequest $interrupt = null,
    ): Generator;
}
