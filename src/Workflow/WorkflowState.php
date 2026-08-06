<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_diff_key;
use function serialize;
use function unserialize;

class WorkflowState
{
    protected ?InterruptRequest $interrupt = null;

    public function __construct(protected array $data = [])
    {
    }

    /**
     * Mark this state as paused for external input.
     *
     * Set by the executor when traversal terminates on an InterruptEvent, so
     * that callers of run()/events() can detect the pause without catching an
     * exception.
     */
    public function markAsInterrupted(InterruptRequest $request): void
    {
        $this->interrupt = $request;
    }

    /**
     * Clear any paused-for-input marker.
     *
     * The interrupt status describes the outcome of a single run, so the
     * executor resets it at the start of each run.
     */
    public function clearInterrupt(): void
    {
        $this->interrupt = null;
    }

    /**
     * Whether the workflow is paused waiting for external input.
     */
    public function isInterrupted(): bool
    {
        return $this->interrupt instanceof InterruptRequest;
    }

    /**
     * The interrupt request describing the pause, or null when not interrupted.
     */
    public function getInterruptRequest(): ?InterruptRequest
    {
        return $this->interrupt;
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
     * Create a deep copy for complete isolation in parallel branches: nested
     * objects get their own independent instances, eliminating state leakage.
     * State must be serializable anyway for durable persistence.
     */
    public function __clone(): void
    {
        $this->data = unserialize(serialize($this->data));
    }
}
