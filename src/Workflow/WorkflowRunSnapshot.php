<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/** A detached view of the run; use both identity stamps when continuing it. */
final class WorkflowRunSnapshot
{
    /**
     * @param int|null $leaseExpiresAt     Unix time at which the lease on the run expires; null when it has none.
     * @param int      $deferredInterrupts Interruptions waiting behind the current one.
     * @param string|null $tag             The label its caller gave the run at the start; null when it gave none.
     */
    public function __construct(
        public readonly string $runId,
        public readonly WorkflowStatus $status,
        public readonly int $executionAttempt,
        public readonly ?InterruptRequest $interrupt,
        public readonly string $workflowId,
        public readonly Event $startEvent,
        public readonly ?int $leaseExpiresAt = null,
        public readonly int $deferredInterrupts = 0,
        public readonly ?string $tag = null,
    ) {
    }
}
