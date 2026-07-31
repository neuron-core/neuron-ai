<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;

class InMemoryPersistence implements PersistenceInterface
{
    /** @var array<string, array<string, StepResult>> keyed by runId then stepId */
    protected array $storage = [];

    public function save(string $runId, string $stepId, StepResult $result): void
    {
        $this->storage[$runId][$stepId] = $result;
    }

    public function load(string $runId, string $stepId): ?StepResult
    {
        return $this->storage[$runId][$stepId] ?? null;
    }

    public function delete(string $runId): void
    {
        unset($this->storage[$runId]);
    }
}
