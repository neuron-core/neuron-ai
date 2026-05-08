<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

interface StepStoreInterface
{
    public function save(string $workflowId, string $stepId, StepResult $result): void;
    public function load(string $workflowId, string $stepId): ?StepResult;
    public function delete(string $workflowId): void;
}
