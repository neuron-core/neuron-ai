<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function serialize;
use function unserialize;

class InMemoryPersistence implements PersistenceInterface, SerializablePersistenceInterface
{
    private array $storage = [];

    public function serialize(WorkflowInterrupt $interrupt): string
    {
        return serialize($interrupt);
    }

    public function unserialize(string $data): WorkflowInterrupt
    {
        return unserialize($data);
    }

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        $this->storage[$workflowId] = $this->serialize($interrupt);
    }

    public function load(string $workflowId): WorkflowInterrupt
    {
        if (!isset($this->storage[$workflowId])) {
            throw new WorkflowException("No saved workflow found for ID: {$workflowId}.");
        }

        return $this->unserialize($this->storage[$workflowId]);
    }

    public function delete(string $workflowId): void
    {
        unset($this->storage[$workflowId]);
    }
}
