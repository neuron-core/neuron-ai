<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\WorkflowInterrupt;

class FilePersistence implements PersistenceInterface
{
    public function __construct(
        protected string $directory,
        protected string $prefix = 'neuron_workflow_',
        protected string $ext = '.store'
    ) {
        if (!\is_dir($this->directory)) {
            throw new WorkflowException("Directory '{$this->directory}' does not exist");
        }
    }

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        \file_put_contents($this->getFilePath($workflowId), \serialize($interrupt));
    }

    public function load(string $workflowId): WorkflowInterrupt
    {
        if (!\is_file($this->getFilePath($workflowId))) {
            throw new WorkflowException("No saved workflow found for ID: {$workflowId}.");
        }

        return \unserialize(\file_get_contents($this->getFilePath($workflowId)));
    }

    public function delete(string $workflowId): void
    {
        if (\file_exists($this->getFilePath($workflowId))) {
            \unlink($this->getFilePath($workflowId));
        }
    }

    protected function getFilePath(string $workflowId): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$this->prefix.$workflowId.$this->ext;
    }
}
