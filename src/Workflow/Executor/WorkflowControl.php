<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;

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
        public readonly ?WorkflowState $checkpointState = null,
        public readonly ?WorkflowState $completedState = null,
    ) {
    }

    public function claim(?int $leaseExpiresAt): self
    {
        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Running,
            executionAttempt: $this->executionAttempt + 1,
            leaseExpiresAt: $leaseExpiresAt,
            nextInterruptId: $this->nextInterruptId,
            interrupts: $this->interrupts,
            checkpointState: $this->checkpointState,
        );
    }

    public function heartbeat(?int $leaseExpiresAt): self
    {
        return new self(
            runId: $this->runId,
            status: $this->status,
            executionAttempt: $this->executionAttempt,
            leaseExpiresAt: $leaseExpiresAt,
            nextInterruptId: $this->nextInterruptId,
            interrupts: $this->interrupts,
            checkpointState: $this->checkpointState,
            completedState: $this->completedState,
        );
    }

    public function addInterrupt(ActiveInterrupt $active): self
    {
        $interrupts = $this->interrupts;
        $interrupts[$active->request->getId()] = $active;

        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Running,
            executionAttempt: $this->executionAttempt,
            leaseExpiresAt: $this->leaseExpiresAt,
            nextInterruptId: $active->request->getId() + 1,
            interrupts: $interrupts,
            checkpointState: $this->checkpointState,
        );
    }

    public function removeInterrupt(int $id): self
    {
        $interrupts = $this->interrupts;
        unset($interrupts[$id]);

        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Running,
            executionAttempt: $this->executionAttempt,
            leaseExpiresAt: $this->leaseExpiresAt,
            nextInterruptId: $this->nextInterruptId,
            interrupts: $interrupts,
            checkpointState: $this->checkpointState,
        );
    }

    /** @param array<int, \NeuronAI\Workflow\Resume\ResumeInput> $inputs */
    public function withInputs(array $inputs): self
    {
        $interrupts = $this->interrupts;
        foreach ($inputs as $id => $input) {
            if (isset($interrupts[$id])) {
                $interrupts[$id] = $interrupts[$id]->withInput($input);
            }
        }

        return new self(
            runId: $this->runId,
            status: $this->status,
            executionAttempt: $this->executionAttempt,
            leaseExpiresAt: $this->leaseExpiresAt,
            nextInterruptId: $this->nextInterruptId,
            interrupts: $interrupts,
            checkpointState: $this->checkpointState,
            completedState: $this->completedState,
        );
    }

    public function suspended(WorkflowState $state): self
    {
        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Suspended,
            executionAttempt: $this->executionAttempt,
            nextInterruptId: $this->nextInterruptId,
            interrupts: $this->interrupts,
            checkpointState: $state,
        );
    }

    public function failed(): self
    {
        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Failed,
            executionAttempt: $this->executionAttempt,
            nextInterruptId: $this->nextInterruptId,
            interrupts: $this->interrupts,
            checkpointState: $this->checkpointState,
        );
    }

    public function completed(WorkflowState $state): self
    {
        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Completed,
            executionAttempt: $this->executionAttempt,
            nextInterruptId: $this->nextInterruptId,
            completedState: $state,
        );
    }
}
