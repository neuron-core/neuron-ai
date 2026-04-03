<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_diff_key;
use function is_array;
use function is_object;

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
        return array_key_exists($key, $this->data);
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Missing keys in the state are simply ignored.
     *
     * @param string[] $keys
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    public function except(string ...$keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Create a deep copy for complete isolation in parallel branches.
     */
    public function __clone(): void
    {
        // Deep clone nested arrays/objects if needed
        $this->data = $this->deepCloneArray($this->data);
    }

    /**
     * Recursively clone arrays and objects.
     */
    protected function deepCloneArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->deepCloneArray($value);
            } elseif (is_object($value)) {
                $result[$key] = clone $value;
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
