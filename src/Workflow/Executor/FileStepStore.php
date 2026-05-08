<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function serialize;
use function unlink;
use function unserialize;
use function glob;
use function mkdir;
use function rmdir;

use const DIRECTORY_SEPARATOR;

class FileStepStore implements StepStoreInterface
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
        $workflowDir = $this->directory . DIRECTORY_SEPARATOR . $workflowId;

        if (!is_dir($workflowDir)) {
            mkdir($workflowDir, 0o777, true);
        }

        file_put_contents(
            $workflowDir . DIRECTORY_SEPARATOR . $stepId . '.step',
            serialize($result),
        );
    }

    public function load(string $workflowId, string $stepId): ?StepResult
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $workflowId . DIRECTORY_SEPARATOR . $stepId . '.step';

        if (!is_file($path)) {
            return null;
        }

        return unserialize(file_get_contents($path));
    }

    public function delete(string $workflowId): void
    {
        $workflowDir = $this->directory . DIRECTORY_SEPARATOR . $workflowId;

        if (!is_dir($workflowDir)) {
            return;
        }

        $files = glob($workflowDir . DIRECTORY_SEPARATOR . '*.step') ?: [];

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($workflowDir);
    }
}
