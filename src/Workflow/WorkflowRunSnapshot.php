<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/** A detached view of the run; use both identity stamps when continuing it. */
final class WorkflowRunSnapshot
{
    /** @param array<int, InterruptRequest> $interrupts */
    public function __construct(
        public readonly string $runId,
        public readonly WorkflowStatus $status,
        public readonly int $executionAttempt,
        public readonly array $interrupts,
    ) {
    }
}
