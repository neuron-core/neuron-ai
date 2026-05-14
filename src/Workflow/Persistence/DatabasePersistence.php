<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PDO;

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;

class DatabasePersistence implements PersistenceInterface, SerializablePersistenceInterface
{
    public function __construct(
        protected PDO $pdo,
        protected string $table = 'workflow_interrupts'
    ) {
    }

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
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (workflow_id, interrupt, created_at, updated_at)
            VALUES (:id, :interrupt, NOW(), NOW())
            ON DUPLICATE KEY UPDATE interrupt = VALUES(interrupt), updated_at = NOW()
        ");

        $stmt->execute([
            'id' => $workflowId,
            // Simple Base64 string is compatible with all databases
            'interrupt' => base64_encode($this->serialize($interrupt)),
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

        $interruptData = base64_decode((string) $record['interrupt'], true);

        if ($interruptData === false) {
            $interruptData = $record['interrupt']; // This makes sure that previous records still work
        }

        return $this->unserialize($interruptData);
    }

    public function delete(string $workflowId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE workflow_id = :id");
        $stmt->execute(['id' => $workflowId]);
    }
}
