<?php

declare(strict_types=1);

namespace NeuronAI\WorkflowV2\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\WorkflowInterrupt;

class InMemoryPersistence implements PersistenceInterface
{
    private array $storage = [];

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        $this->storage[$workflowId] = $interrupt;
    }

    public function load(string $workflowId): WorkflowInterrupt
    {
        return $this->storage[$workflowId] ?? throw new WorkflowException("No saved workflow found for ID: {$workflowId}");
    }

    public function delete(string $workflowId): void
    {
        unset($this->storage[$workflowId]);
    }
}
