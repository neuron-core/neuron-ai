<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

interface PersistenceInterface
{
    public function save(string $workflowId, WorkflowInterrupt $interrupt): void;
    public function load(string $workflowId): WorkflowInterrupt;
    public function delete(string $workflowId): void;
}
