<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\Serializer;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function array_key_exists;

/**
 * Persistence protocol for one Workflow run partition.
 *
 * The executor owns lifecycle decisions; this store owns reserved records,
 * serialization, and the raw control snapshot used to fence every mutation.
 */
final class WorkflowRunStore
{
    protected const IGNITION_KEY = '__ignition';
    protected const CONTROL_KEY = '__control';

    /**
     * Workflow state records live beside control, never inside it, so the
     * fence stays small. They are keyed under the run ID like steps: a
     * process holding an older control cannot read a successor generation's
     * state, only its own or nothing.
     */
    protected const CHECKPOINT_KEY = '__checkpoint';
    protected const OUTCOME_KEY = '__outcome';

    protected ?WorkflowControl $control = null;

    protected ?string $controlSnapshot = null;

    /**
     * Segment-local view of the run's records: serialized bytes per key, or
     * null for a record known to be absent. It can never go stale while the
     * control fence holds, because every other execution path changes
     * control before it can add a record. Reading each key at most once
     * removes the duplicate lookups of a continuation; entries are replaced
     * by the exact bytes a confirmed write committed.
     *
     * @var array<string, string|null>
     */
    protected array $records = [];

    /**
     * True after this store ignited the partition: no record can exist that
     * it did not write, so unknown keys are absent without a backend read.
     */
    protected bool $freshRun = false;

    public function __construct(
        protected PersistenceInterface $persistence,
        protected Serializer $serializer,
        protected string $workflowId,
    ) {
    }

    public function initialize(WorkflowControl $control, Ignition $ignition): bool
    {
        $controlSnapshot = $this->serializer->serialize($control);

        if (!$this->persistence->initializeIfAbsent(
            $this->workflowId,
            self::CONTROL_KEY,
            $controlSnapshot,
            [
                self::IGNITION_KEY => $this->serializer->serialize($ignition),
            ],
        )) {
            return false;
        }

        $this->control = $control;
        $this->controlSnapshot = $controlSnapshot;
        $this->resetRecords(freshRun: true);

        return true;
    }

    public function loadControl(): ?WorkflowControl
    {
        $this->resetRecords();

        $raw = $this->persistence->get($this->workflowId, self::CONTROL_KEY);
        if ($raw === null) {
            return null;
        }

        $control = $this->serializer->unserialize($raw);
        if (!$control instanceof WorkflowControl) {
            throw new WorkflowException("Invalid __control record for workflow ID '{$this->workflowId}'.");
        }

        $this->control = $control;
        $this->controlSnapshot = $raw;

        return $control;
    }

    public function loadIgnition(): ?Ignition
    {
        $raw = $this->persistence->get($this->workflowId, self::IGNITION_KEY);
        $ignition = $raw === null ? null : $this->serializer->unserialize($raw);

        return $ignition instanceof Ignition ? $ignition : null;
    }

    /**
     * @throws WorkflowException
     */
    public function control(): WorkflowControl
    {
        if (!$this->hasControl()) {
            throw new WorkflowException("Workflow ID '{$this->workflowId}' has no loaded control record.");
        }

        return $this->control;
    }

    public function hasControl(): bool
    {
        return $this->control instanceof WorkflowControl;
    }

    public function replaceControl(WorkflowControl $control): void
    {
        $this->commit([], $control);
    }

    public function commitStep(StepResult $step, ?WorkflowControl $control = null): void
    {
        $this->commit([$this->recordKey($step->getStepId()) => $step], $control);
    }

    /** The suspended run's state, committed together with its suspension. */
    public function commitCheckpoint(WorkflowState $checkpoint, WorkflowControl $control): void
    {
        $this->commit([$this->recordKey(self::CHECKPOINT_KEY) => $checkpoint], $control);
    }

    /** The retained terminal state, committed together with its completion. */
    public function commitOutcome(WorkflowState $outcome, WorkflowControl $control): void
    {
        $this->commit([$this->recordKey(self::OUTCOME_KEY) => $outcome], $control);
    }

    /**
     * @throws WorkflowException
     */
    public function deleteIfOwned(): bool
    {
        $deleted = $this->persistence->deleteIfUnchanged(
            $this->workflowId,
            self::CONTROL_KEY,
            $this->expectedControlValue(),
        );

        if ($deleted) {
            $this->control = null;
            $this->controlSnapshot = null;
            $this->resetRecords();
        }

        return $deleted;
    }

    /**
     * @throws PersistenceException
     */
    public function loadStep(string $stepId): ?StepResult
    {
        return $this->loadRecord($stepId, StepResult::class, 'step');
    }

    /**
     * @throws PersistenceException
     */
    public function loadCheckpoint(): ?WorkflowState
    {
        return $this->loadRecord(self::CHECKPOINT_KEY, WorkflowState::class, 'checkpoint');
    }

    /**
     * @throws PersistenceException
     */
    public function loadOutcome(): ?WorkflowState
    {
        return $this->loadRecord(self::OUTCOME_KEY, WorkflowState::class, 'outcome');
    }

    public function memoizer(string $stepId): StepMemoizer
    {
        return new StepMemoizer($this, $stepId);
    }

    public function memo(string $stepId, string $name, Closure $operation): mixed
    {
        $key = $this->memoKey($stepId, $name);
        $recorded = $this->readRecord($key);
        if ($recorded !== null) {
            return $this->serializer->unserialize($recorded);
        }

        $value = $operation();
        $this->commit([$key => $value]);

        return $value;
    }

    public function recallMemo(string $stepId, string $name): mixed
    {
        $recorded = $this->readRecord($this->memoKey($stepId, $name));

        return $recorded === null ? null : $this->serializer->unserialize($recorded);
    }

    /**
     * A run-scoped record of the expected class, or null when absent. A
     * present record that cannot be decoded or has another type fails loudly:
     * treating it as absent would silently re-execute committed work.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     * @throws PersistenceException
     */
    protected function loadRecord(string $name, string $class, string $kind): ?object
    {
        $key = $this->recordKey($name);
        $raw = $this->readRecord($key);
        if ($raw === null) {
            return null;
        }

        try {
            $record = $this->serializer->unserialize($raw);
        } catch (Throwable $e) {
            throw new PersistenceException(
                "Invalid {$kind} record '{$key}' for workflow ID '{$this->workflowId}'.",
                $e->getCode(),
                previous: $e,
            );
        }

        if (!$record instanceof $class) {
            throw new PersistenceException(
                "Invalid {$kind} record '{$key}' for workflow ID '{$this->workflowId}'."
            );
        }

        return $record;
    }

    /**
     * The serialized bytes of a run record, or null when absent. Every
     * access deserializes afresh, so callers always get a detached snapshot.
     */
    protected function readRecord(string $key): ?string
    {
        if (!array_key_exists($key, $this->records)) {
            $this->records[$key] = $this->freshRun ? null : $this->persistence->get($this->workflowId, $key);
        }

        return $this->records[$key];
    }

    /** @param array<string, mixed> $records */
    protected function commit(array $records, ?WorkflowControl $control = null): void
    {
        $committed = $this->serializeRecords($records);
        $writes = $committed;
        $controlSnapshot = $this->expectedControlValue();
        if ($control !== null && $control !== $this->control) {
            $controlSnapshot = $this->serializer->serialize($control);
            $writes[self::CONTROL_KEY] = $controlSnapshot;
        }

        if (!$this->persistence->writeIfUnchanged(
            $this->workflowId,
            self::CONTROL_KEY,
            $this->expectedControlValue(),
            $writes,
        )) {
            $current = $this->control();
            throw new WorkflowException(
                "Stale execution attempt {$current->executionAttempt} cannot write workflow ID '{$this->workflowId}'."
            );
        }

        $this->control = $control ?? $this->control;
        $this->controlSnapshot = $controlSnapshot;
        foreach ($committed as $key => $value) {
            $this->records[$key] = $value;
        }
    }

    protected function resetRecords(bool $freshRun = false): void
    {
        $this->records = [];
        $this->freshRun = $freshRun;
    }

    protected function recordKey(string $name): string
    {
        return $this->control()->runId . '/' . $name;
    }

    protected function memoKey(string $stepId, string $name): string
    {
        return $this->recordKey($stepId) . '::' . $name;
    }

    /**
     * @throws WorkflowException
     */
    protected function expectedControlValue(): string
    {
        if ($this->controlSnapshot === null) {
            throw new WorkflowException("Workflow ID '{$this->workflowId}' has no loaded control snapshot.");
        }

        return $this->controlSnapshot;
    }

    /**
     * @param array<string, mixed> $records
     * @return array<string, string>
     */
    protected function serializeRecords(array $records): array
    {
        $serialized = [];
        foreach ($records as $key => $value) {
            $serialized[$key] = $this->serializer->serialize($value);
        }

        return $serialized;
    }
}
