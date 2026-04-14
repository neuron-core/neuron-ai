<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PDO;

use function serialize;
use function unserialize;
use function base64_decode;

class DatabasePersistence implements PersistenceInterface
{
    public function __construct(
        protected PDO $pdo,
        protected string $table = 'workflow_interrupts'
    ) {
    }

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (workflow_id, interrupt, created_at, updated_at)
            VALUES (:id, :interrupt, NOW(), NOW())
            ON DUPLICATE KEY UPDATE interrupt = VALUES(interrupt), updated_at = NOW()
        ");

        $stmt->execute([
            'id' => $workflowId,
            'interrupt' => serialize($interrupt),
        ]);
    }

    /**
     * @throws WorkflowException
     */
    public function load(string $workflowId): WorkflowInterrupt
    {
        $stmt = $this->pdo->prepare("SELECT interrupt FROM {$this->table} WHERE workflow_id = :id");
        $stmt->execute(['id' => $workflowId]);
        $record = $stmt->fetch();

        if (!$record) {
            throw new WorkflowException("No saved workflow found for ID: {$workflowId}.");
        }

        $interruptData = @unserialize(
            base64_decode((string) $record['interrupt'], true)
        );

        if ($interruptData === false) {
            $interruptData = unserialize($record['interrupt']); // This makes sure that previous records still work
        }

        if ($interruptData === false) {
            throw new WorkflowException("Failed to unserialize saved workflow for ID: {$workflowId}.");
        }

        return $interruptData;
    }

    public function delete(string $workflowId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE workflow_id = :id");
        $stmt->execute(['id' => $workflowId]);
    }
}
