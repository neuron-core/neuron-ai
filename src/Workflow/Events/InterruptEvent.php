<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Terminal event signalling that the workflow is paused for external input.
 *
 * Returned by a node (or surfaced by a pausing middleware) instead of throwing
 * an exception. Like {@see StopEvent}, it terminates traversal — but the
 * workflow is paused rather than complete. The executor surfaces it as the
 * final yielded event and marks the returned {@see \NeuronAI\Workflow\WorkflowState}
 * as interrupted, carrying the request outbound.
 *
 * Resume is driven by step replay: the interrupted step is persisted as an
 * interrupted StepResult and re-run with the user's resume request, so this
 * event carries only the request — no node/state/branch context, and no
 * durability detail like the interrupted step's id (that stays with the step
 * engine, which re-derives it during replay).
 */
class InterruptEvent implements Event
{
    public function __construct(
        public readonly InterruptRequest $request,
    ) {
    }
}
