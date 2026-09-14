<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\WorkflowStatus;

use function array_merge;
use function get_object_vars;

/**
 * The lifecycle authority of one run: the value every mutation is fenced on.
 * It stays small on purpose — the suspended checkpoint and the retained
 * outcome are separate records — so the fence never carries workflow state.
 * Each transition names only what it changes; everything else carries over.
 */
final class WorkflowControl
{
    /**
     * @param list<string> $pendingSteps Deferred interruptions in arrival order.
     */
    public function __construct(
        public readonly string $runId,
        public readonly WorkflowStatus $status,
        public readonly int $executionAttempt = 1,
        public readonly ?int $leaseExpiresAt = null,
        public readonly int $nextInterruptId = 1,
        public readonly ?ActiveInterrupt $interrupt = null,
        public readonly array $pendingSteps = [],
    ) {
    }

    public function claim(?int $leaseExpiresAt): self
    {
        return $this->with([
            'status' => WorkflowStatus::Running,
            'executionAttempt' => $this->executionAttempt + 1,
            'leaseExpiresAt' => $leaseExpiresAt,
        ]);
    }

    public function heartbeat(?int $leaseExpiresAt): self
    {
        return $this->with(['leaseExpiresAt' => $leaseExpiresAt]);
    }

    public function addInterrupt(ActiveInterrupt $active): self
    {
        return $this->with([
            'nextInterruptId' => $active->request->getId() + 1,
            'interrupt' => $this->interrupt ?? $active,
            'pendingSteps' => $this->interrupt === null
                ? $this->pendingSteps
                : [...$this->pendingSteps, $active->stepId],
        ]);
    }

    public function removeInterrupt(?ActiveInterrupt $next): self
    {
        return $this->with([
            'interrupt' => $next,
            'pendingSteps' => array_slice($this->pendingSteps, 1),
        ]);
    }

    public function withInput(ResumeInput $input): self
    {
        return $this->with(['interrupt' => $this->interrupt->withInput($input)]);
    }

    /**
     * Suspension and failure clear the lease: no process is intentionally
     * executing the run any more.
     */
    public function suspended(): self
    {
        return $this->with(['status' => WorkflowStatus::Suspended, 'leaseExpiresAt' => null]);
    }

    public function failed(): self
    {
        return $this->with(['status' => WorkflowStatus::Failed, 'leaseExpiresAt' => null]);
    }

    public function completed(): self
    {
        return $this->with([
            'status' => WorkflowStatus::Completed,
            'leaseExpiresAt' => null,
            'interrupt' => null,
            'pendingSteps' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    protected function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
