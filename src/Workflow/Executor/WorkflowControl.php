<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;

final class WorkflowControl
{
    /**
     * @param array<int, ActiveSuspension> $suspensions
     */
    public function __construct(
        public readonly string $runId,
        public readonly WorkflowStatus $status,
        public readonly int $executionAttempt = 1,
        public readonly ?int $leaseExpiresAt = null,
        public readonly int $nextSuspensionId = 1,
        public readonly array $suspensions = [],
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
            nextSuspensionId: $this->nextSuspensionId,
            suspensions: $this->suspensions,
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
            nextSuspensionId: $this->nextSuspensionId,
            suspensions: $this->suspensions,
            checkpointState: $this->checkpointState,
            completedState: $this->completedState,
        );
    }

    public function addSuspension(ActiveSuspension $active): self
    {
        $suspensions = $this->suspensions;
        $suspensions[$active->suspension->id] = $active;

        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Running,
            executionAttempt: $this->executionAttempt,
            leaseExpiresAt: $this->leaseExpiresAt,
            nextSuspensionId: $active->suspension->id + 1,
            suspensions: $suspensions,
            checkpointState: $this->checkpointState,
        );
    }

    public function removeSuspension(int $id): self
    {
        $suspensions = $this->suspensions;
        unset($suspensions[$id]);

        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Running,
            executionAttempt: $this->executionAttempt,
            leaseExpiresAt: $this->leaseExpiresAt,
            nextSuspensionId: $this->nextSuspensionId,
            suspensions: $suspensions,
            checkpointState: $this->checkpointState,
        );
    }

    /** @param array<int, \NeuronAI\Workflow\Resume\ResumeInput> $inputs */
    public function withInputs(array $inputs): self
    {
        $suspensions = $this->suspensions;
        foreach ($inputs as $id => $input) {
            if (isset($suspensions[$id])) {
                $suspensions[$id] = $suspensions[$id]->withInput($input);
            }
        }

        return new self(
            runId: $this->runId,
            status: $this->status,
            executionAttempt: $this->executionAttempt,
            leaseExpiresAt: $this->leaseExpiresAt,
            nextSuspensionId: $this->nextSuspensionId,
            suspensions: $suspensions,
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
            nextSuspensionId: $this->nextSuspensionId,
            suspensions: $this->suspensions,
            checkpointState: $state,
        );
    }

    public function failed(): self
    {
        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Failed,
            executionAttempt: $this->executionAttempt,
            nextSuspensionId: $this->nextSuspensionId,
            suspensions: $this->suspensions,
            checkpointState: $this->checkpointState,
        );
    }

    public function completed(WorkflowState $state): self
    {
        return new self(
            runId: $this->runId,
            status: WorkflowStatus::Completed,
            executionAttempt: $this->executionAttempt,
            nextSuspensionId: $this->nextSuspensionId,
            completedState: $state,
        );
    }
}
