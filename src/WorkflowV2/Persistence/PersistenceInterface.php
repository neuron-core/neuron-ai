<?php

declare(strict_types=1);

namespace NeuronAI\WorkflowV2\Persistence;

use NeuronAI\Workflow\WorkflowInterrupt;

interface PersistenceInterface
{
    public function save(string $workflowId, WorkflowInterrupt $interrupt): void;
    public function load(string $workflowId): WorkflowInterrupt;
    public function delete(string $workflowId): void;
}
