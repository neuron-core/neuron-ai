<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;
use PDO;

class DatabasePersistence implements PersistenceInterface
{
    public function __construct(
        protected PDO $pdo,
        protected string $table = 'workflow_steps',
        protected Serializer $serializer = new PhpSerializer(),
    ) {
    }

    public function save(string $workflowId, string $stepId, StepResult $result): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (workflow_id, step_id, result, created_at, updated_at)
            VALUES (:workflow_id, :step_id, :result, NOW(), NOW())
            ON DUPLICATE KEY UPDATE result = VALUES(result), updated_at = NOW()
        ");

        $stmt->execute([
            'workflow_id' => $workflowId,
            'step_id' => $stepId,
            'result' => $this->serializer->serialize($result),
        ]);
    }

    public function load(string $workflowId, string $stepId): ?StepResult
    {
        $stmt = $this->pdo->prepare(
            "SELECT result FROM {$this->table} WHERE workflow_id = :workflow_id AND step_id = :step_id",
        );
        $stmt->execute(['workflow_id' => $workflowId, 'step_id' => $stepId]);
        $record = $stmt->fetch();

        if (!$record) {
            return null;
        }

        return $this->serializer->unserialize((string) $record['result']);
    }

    public function delete(string $workflowId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE workflow_id = :workflow_id");
        $stmt->execute(['workflow_id' => $workflowId]);
    }
}
