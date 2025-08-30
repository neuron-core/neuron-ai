<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

class WorkflowState
{
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
        return \array_key_exists($key, $this->data);
    }

    /**
     * Missing keys in the state are simply ignored.
     *
     * @param string[] $keys
     */
    public function only(array $keys): array
    {
        return \array_intersect_key($this->data, \array_flip($keys));
    }

    public function all(): array
    {
        return $this->data;
    }
}
