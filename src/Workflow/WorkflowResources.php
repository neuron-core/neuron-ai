<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use function array_key_exists;

/**
 * What a run can use: the services, lists and working objects its nodes and
 * middleware share. The definition builds them for every execution segment;
 * they are never persisted, so anything a resume needs again is rebuilt. What
 * the run knows belongs in the state.
 */
class WorkflowResources
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(protected array $data = [])
    {
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}
