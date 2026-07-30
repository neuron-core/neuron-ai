<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

/**
 * Emitted when a workflow is interrupted and is waiting for external input.
 *
 * Unlike {@see AgentError}, an interruption is not a failure: the workflow has
 * been paused (and persisted) so it can be resumed. Listeners can use this to
 * trigger alerts, notifications, or UI prompts without conflating interrupts
 * with real errors.
 */
class WorkflowInterrupted
{
    public function __construct(public WorkflowInterrupt $interrupt)
    {
    }
}
