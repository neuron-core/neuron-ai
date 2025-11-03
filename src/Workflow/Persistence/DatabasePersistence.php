<?php

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\WorkflowInterrupt;

class DatabasePersistence implements PersistenceInterface
{
    public function __construct(
        protected \PDO $pdo,
        protected string $table = 'workflow_interrupts'
    ) {}

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (workflow_id, data, created_at, updated_at)
            VALUES (:id, :data, NOW(), NOW())
            ON DUPLICATE KEY UPDATE data = :data, updated_at = NOW()
        ");

        $stmt->execute([
            'id' => $workflowId,
            'data' => \serialize($interrupt),
        ]);
    }

    public function load(string $workflowId): WorkflowInterrupt
    {
        $stmt = $this->pdo->prepare("SELECT data FROM {$this->table} WHERE workflow_id = :id");
        $stmt->execute(['id' => $workflowId]);
        $result = $stmt->fetch();

        if (!$result) {
            throw new WorkflowException("No saved workflow found for ID: {$workflowId}.");
        }

        return \unserialize($result['data']);
    }

    public function delete(string $workflowId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE workflow_id = :id");
        $stmt->execute(['id' => $workflowId]);
    }
}
