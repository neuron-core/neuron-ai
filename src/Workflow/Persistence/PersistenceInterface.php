<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;

interface PersistenceInterface
{
    public function save(string $workflowId, string $stepId, StepResult $result): void;
    public function load(string $workflowId, string $stepId): ?StepResult;
    public function delete(string $workflowId): void;
    public function getMaxGeneration(string $workflowId): int;
}
