<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;

interface PersistenceInterface
{
    public function save(string $runId, string $stepId, StepResult $result): void;
    public function load(string $runId, string $stepId): ?StepResult;
    public function delete(string $runId): void;
}
