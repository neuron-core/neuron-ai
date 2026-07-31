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

    public function save(string $runId, string $stepId, StepResult $result): void
    {
        // MySQL/MariaDB have no ON CONFLICT; PostgreSQL and SQLite have no ON DUPLICATE KEY.
        $upsert = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE result = VALUES(result), updated_at = CURRENT_TIMESTAMP'
            : 'ON CONFLICT (run_id, step_id) DO UPDATE SET result = excluded.result, updated_at = CURRENT_TIMESTAMP';

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (run_id, step_id, result, created_at, updated_at)
            VALUES (:run_id, :step_id, :result, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            {$upsert}
        ");

        $stmt->execute([
            'run_id' => $runId,
            'step_id' => $stepId,
            'result' => $this->serializer->serialize($result),
        ]);
    }

    public function load(string $runId, string $stepId): ?StepResult
    {
        $stmt = $this->pdo->prepare(
            "SELECT result FROM {$this->table} WHERE run_id = :run_id AND step_id = :step_id",
        );
        $stmt->execute(['run_id' => $runId, 'step_id' => $stepId]);
        $record = $stmt->fetch();

        if (!$record) {
            return null;
        }

        return $this->serializer->unserialize((string) $record['result']);
    }

    public function delete(string $runId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE run_id = :run_id");
        $stmt->execute(['run_id' => $runId]);
    }
}
