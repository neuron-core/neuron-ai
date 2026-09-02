<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\Serializer;
use Throwable;

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

    protected ?WorkflowControl $control = null;

    protected ?string $controlSnapshot = null;

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

        return true;
    }

    public function loadControl(): ?WorkflowControl
    {
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

    /**
     * @param array<string, mixed> $records
     * @throws WorkflowException
     */
    public function replaceControl(WorkflowControl $control, array $records = []): void
    {
        $controlSnapshot = $this->serializer->serialize($control);
        $writes = $this->serializeRecords($records);
        $writes[self::CONTROL_KEY] = $controlSnapshot;

        if (!$this->persistence->writeIfUnchanged(
            $this->workflowId,
            self::CONTROL_KEY,
            $this->expectedControlValue(),
            $writes,
        )) {
            $current = $this->control();
            throw new WorkflowException(
                "Execution ownership was lost for workflow ID '{$this->workflowId}', "
                . "run '{$current->runId}', attempt {$current->executionAttempt}."
            );
        }

        $this->control = $control;
        $this->controlSnapshot = $controlSnapshot;
    }

    /**
     * @param array<string, mixed> $records
     * @throws WorkflowException
     */
    public function writeRecords(array $records): void
    {
        $this->writeSerialized($this->serializeRecords($records));
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
        }

        return $deleted;
    }

    /**
     * @throws PersistenceException
     */
    public function loadStep(string $key): ?StepResult
    {
        $raw = $this->persistence->get($this->workflowId, $key);
        if ($raw === null) {
            return null;
        }

        try {
            $step = $this->serializer->unserialize($raw);
        } catch (Throwable $e) {
            throw new PersistenceException(
                "Invalid step record '{$key}' for workflow ID '{$this->workflowId}'.",
                $e->getCode(),
                previous: $e,
            );
        }

        if (!$step instanceof StepResult) {
            throw new PersistenceException(
                "Invalid step record '{$key}' for workflow ID '{$this->workflowId}'."
            );
        }

        return $step;
    }

    public function memoizer(string $recordKey): StepMemoizer
    {
        return new StepMemoizer(
            $this->persistence,
            $this->serializer,
            $this->workflowId,
            $recordKey,
            fn (string $key, string $value) => $this->writeSerialized([$key => $value]),
        );
    }

    /**
     * @param array<string, string> $records
     * @throws WorkflowException
     */
    protected function writeSerialized(array $records): void
    {
        if (!$this->persistence->writeIfUnchanged(
            $this->workflowId,
            self::CONTROL_KEY,
            $this->expectedControlValue(),
            $records,
        )) {
            $control = $this->control();
            throw new WorkflowException(
                "Stale execution attempt {$control->executionAttempt} cannot write workflow ID '{$this->workflowId}'."
            );
        }
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
