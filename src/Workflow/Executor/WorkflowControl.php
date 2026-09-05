<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\WorkflowStatus;

use function array_map;
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
     * @param array<int, ActiveInterrupt> $interrupts
     */
    public function __construct(
        public readonly string $runId,
        public readonly WorkflowStatus $status,
        public readonly int $executionAttempt = 1,
        public readonly ?int $leaseExpiresAt = null,
        public readonly int $nextInterruptId = 1,
        public readonly array $interrupts = [],
    ) {
    }

    /**
     * @return array<int, InterruptRequest>
     */
    public function interruptRequests(): array
    {
        return array_map(
            static fn (ActiveInterrupt $active): InterruptRequest => $active->request,
            $this->interrupts,
        );
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
        $interrupts = $this->interrupts;
        $interrupts[$active->request->getId()] = $active;

        return $this->with([
            'status' => WorkflowStatus::Running,
            'nextInterruptId' => $active->request->getId() + 1,
            'interrupts' => $interrupts,
        ]);
    }

    public function removeInterrupt(int $id): self
    {
        $interrupts = $this->interrupts;
        unset($interrupts[$id]);

        return $this->with([
            'status' => WorkflowStatus::Running,
            'interrupts' => $interrupts,
        ]);
    }

    /**
     * @param array<int, ResumeInput> $inputs
     */
    public function withInputs(array $inputs): self
    {
        $interrupts = $this->interrupts;
        foreach ($inputs as $id => $input) {
            if (isset($interrupts[$id])) {
                $interrupts[$id] = $interrupts[$id]->withInput($input);
            }
        }

        return $this->with(['interrupts' => $interrupts]);
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
            'interrupts' => [],
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
