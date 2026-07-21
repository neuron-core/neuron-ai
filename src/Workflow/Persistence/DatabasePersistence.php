<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;
use PDO;

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;

class DatabasePersistence implements PersistenceInterface
{
    public function __construct(
        protected PDO $pdo,
        protected string $table = 'workflow_steps',
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
            'result' => base64_encode(serialize($result)),
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

        $data = base64_decode((string) $record['result'], true);

        if ($data === false) {
            $data = $record['result'];
        }

        return unserialize($data);
    }

    public function delete(string $workflowId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE workflow_id = :workflow_id");
        $stmt->execute(['workflow_id' => $workflowId]);
    }
}
