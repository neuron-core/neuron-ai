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
    /** @var array<string, array<string, string>> */
    protected array $cache = [];

    public function __construct(
        protected string $directory,
    ) {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true)) {
            throw new WorkflowException("Unable to create directory '{$this->directory}'");
        }
    }

    public function save(string $workflowId, string $stepId, StepResult $result): void
    {
        $data = $this->getData($workflowId);
        $data[$stepId] = base64_encode(serialize($result));
        $this->cache[$workflowId] = $data;

        file_put_contents($this->filePath($workflowId), json_encode($data, JSON_PRETTY_PRINT));
    }

    public function load(string $workflowId, string $stepId): ?StepResult
    {
        $data = $this->getData($workflowId);

        if (!isset($data[$stepId])) {
            return null;
        }

        return unserialize(base64_decode($data[$stepId]));
    }

    public function delete(string $workflowId): void
    {
        unset($this->cache[$workflowId]);

        $path = $this->filePath($workflowId);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function getMaxGeneration(string $workflowId): int
    {
        $data = $this->getData($workflowId);

        if (empty($data)) {
            return 0;
        }

        $max = 0;
        foreach ($data as $serialized) {
            $result = unserialize(base64_decode($serialized));
            $max = max($max, $result->getGeneration());
        }
        return $max;
    }

    /** @return array<string, string> */
    protected function getData(string $workflowId): array
    {
        if (isset($this->cache[$workflowId])) {
            return $this->cache[$workflowId];
        }

        return $this->cache[$workflowId] = $this->readFile($this->filePath($workflowId));
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
