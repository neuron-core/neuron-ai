<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Executor\StepResult;

use function base64_decode;
use function base64_encode;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function serialize;
use function unlink;
use function unserialize;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;

class FilePersistence implements PersistenceInterface
{
    public function __construct(
        protected string $directory,
    ) {
        if (!is_dir($this->directory)) {
            throw new WorkflowException("Directory '{$this->directory}' does not exist");
        }
    }

    public function save(string $workflowId, string $stepId, StepResult $result): void
    {
        $path = $this->filePath($workflowId);
        $data = $this->readFile($path);
        $data[$stepId] = base64_encode(serialize($result));

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function load(string $workflowId, string $stepId): ?StepResult
    {
        $data = $this->readFile($this->filePath($workflowId));

        if (!isset($data[$stepId])) {
            return null;
        }

        return unserialize(base64_decode($data[$stepId]));
    }

    public function delete(string $workflowId): void
    {
        $path = $this->filePath($workflowId);

        if (is_file($path)) {
            unlink($path);
        }
    }

    /** @return array<string, string> */
    protected function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?? [];
    }

    protected function filePath(string $workflowId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $workflowId . '.workflow';
    }
}
