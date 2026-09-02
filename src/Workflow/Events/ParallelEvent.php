<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use NeuronAI\Exceptions\WorkflowException;

use function is_string;

/**
 * Event that triggers parallel branch execution.
 *
 * Return a ParallelEvent subclass from a fork node and the executor runs all
 * branches (sequentially by default, concurrently with AsyncExecutor). Each
 * branch's StopEvent result is stored via setResult(), then the instance is
 * routed through the event→node map to a join node, whose __invoke() accepts
 * the subclass and reads the results back.
 *
 * Every branch must have a non-empty string name, which becomes its branch ID.
 */
class ParallelEvent implements Event
{
    /** @var array<string, Event> */
    public readonly array $branches;

    /**
     * Branch results keyed by branch ID, populated by the executor as
     * branches complete.
     *
     * @var array<string, mixed>
     */
    protected array $results = [];

    /**
     * @param array<array-key, Event> $branches Events keyed by non-empty string branch names
     * @throws WorkflowException
     */
    public function __construct(array $branches)
    {
        $named = [];
        foreach ($branches as $branch => $event) {
            if (!is_string($branch) || $branch === '') {
                throw new WorkflowException('Parallel branches must use non-empty string names.');
            }

            $named[$branch] = $event;
        }

        $this->branches = $named;
    }

    public function setResult(string $branch, mixed $result): self
    {
        $this->results[$branch] = $result;
        return $this;
    }

    public function getResult(string $branch): mixed
    {
        return $this->results[$branch];
    }

    public function getAllResults(): array
    {
        return $this->results;
    }

    public function hasResult(string $branch): bool
    {
        return isset($this->results[$branch]);
    }
}
