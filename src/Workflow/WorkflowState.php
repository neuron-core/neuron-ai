<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ResumeInputResult;
use function array_diff_key;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_values;
use function serialize;
use function unserialize;

class WorkflowState
{
    /** @var array<int, InterruptRequest> */
    protected array $interruptRequests = [];

    /** @var ResumeInputResult[] */
    protected array $inputResults = [];

    protected WorkflowStatus $status = WorkflowStatus::Running;

    protected ?string $workflowId = null;

    protected ?string $runId = null;

    protected ?int $executionAttempt = null;

    public function __construct(protected array $data = [])
    {
    }

    /**
     * Set by the executor when traversal terminates on an InterruptEvent, so
     * callers of run()/events() can detect the pause without catching an
     * exception.
     */
    /** @param array<int, InterruptRequest> $requests */
    public function markAsSuspended(array $requests): void
    {
        $this->interruptRequests = $requests;
        $this->status = WorkflowStatus::Suspended;
    }

    public function markAsRunning(): void
    {
        $this->interruptRequests = [];
        $this->status = WorkflowStatus::Running;
    }

    /**
     * The interrupt status describes a single run's outcome, so the executor
     * clears it on a clean terminal.
     */
    public function clearInterrupt(): void
    {
        $this->interruptRequests = [];
        $this->status = WorkflowStatus::Completed;
    }

    public function isInterrupted(): bool
    {
        return $this->status === WorkflowStatus::Suspended;
    }

    public function getInterruptRequest(): ?InterruptRequest
    {
        return $this->interruptRequests === []
            ? null
            : array_values($this->interruptRequests)[0];
    }

    /** @return array<int, InterruptRequest> */
    public function getInterruptRequests(): array
    {
        return $this->interruptRequests;
    }

    /** @return ResumeInputResult[] */
    public function getInputResults(): array
    {
        return $this->inputResults;
    }

    /** @param ResumeInputResult[] $results */
    public function setInputResults(array $results): void
    {
        $this->inputResults = $results;
    }

    public function getStatus(): WorkflowStatus
    {
        return $this->status;
    }

    public function getWorkflowId(): ?string
    {
        return $this->workflowId;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function getExecutionAttempt(): ?int
    {
        return $this->executionAttempt;
    }

    /**
     * @internal Set by the Workflow executor.
     */
    public function setExecutionMetadata(
        string $workflowId,
        string $runId,
        int $executionAttempt,
    ): void {
        $this->workflowId = $workflowId;
        $this->runId = $runId;
        $this->executionAttempt = $executionAttempt;
    }

    public function markAsFailed(): void
    {
        $this->status = WorkflowStatus::Failed;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Missing keys in the state are simply ignored.
     *
     * @param string[] $keys
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    public function except(string ...$keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Create a deep copy for complete isolation in parallel branches: nested
     * objects get their own independent instances, eliminating state leakage.
     * State must be serializable anyway for durable persistence.
     */
    public function __clone(): void
    {
        $this->data = unserialize(serialize($this->data));
    }
}
