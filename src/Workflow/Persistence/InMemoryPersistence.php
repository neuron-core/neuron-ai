<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;

use function max;

class InMemoryPersistence implements PersistenceInterface
{
    /** @var array<string, array<string, StepResult>> keyed by workflowId then stepId */
    protected array $storage = [];

    public function save(string $workflowId, string $stepId, StepResult $result): void
    {
        $this->storage[$workflowId][$stepId] = $result;
    }

    public function load(string $workflowId, string $stepId): ?StepResult
    {
        return $this->storage[$workflowId][$stepId] ?? null;
    }

    public function delete(string $workflowId): void
    {
        unset($this->storage[$workflowId]);
    }

    public function getMaxGeneration(string $workflowId): int
    {
        if (!isset($this->storage[$workflowId])) {
            return 0;
        }

        $max = 0;
        foreach ($this->storage[$workflowId] as $result) {
            $max = max($max, $result->getGeneration());
        }
        return $max;
    }
}
