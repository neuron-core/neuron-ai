<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use function serialize;
use function unserialize;

class InMemoryStepStore implements StepStoreInterface
{
    /** @var array<string, array<string, string>> keyed by workflowId then stepId */
    protected array $storage = [];

    public function save(string $workflowId, string $stepId, StepResult $result): void
    {
        $this->storage[$workflowId][$stepId] = serialize($result);
    }

    public function load(string $workflowId, string $stepId): ?StepResult
    {
        if (!isset($this->storage[$workflowId][$stepId])) {
            return null;
        }

        return unserialize($this->storage[$workflowId][$stepId]);
    }

    public function delete(string $workflowId): void
    {
        unset($this->storage[$workflowId]);
    }
}
