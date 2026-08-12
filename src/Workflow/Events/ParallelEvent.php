<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use function array_is_list;

/**
 * Event that triggers parallel branch execution.
 *
 * Return a ParallelEvent subclass from a fork node and the executor runs all
 * branches (sequentially by default, concurrently with AsyncExecutor). Each
 * branch's StopEvent result is stored via setResult(), then the instance is
 * routed through the event→node map to a join node, whose __invoke() accepts
 * the subclass and reads the results back.
 *
 * Associative array keys are used as-is as branch IDs; list entries derive
 * their ID from the event class name.
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
     * @param array<string, Event>|array<int, Event> $branches Named branches, or a list whose IDs derive from each event's class.
     */
    public function __construct(array $branches)
    {
        if (array_is_list($branches)) {
            $named = [];
            foreach ($branches as $branchEvent) {
                $named[$branchEvent::class] = $branchEvent;
            }
            $this->branches = $named;
        } else {
            $this->branches = $branches;
        }
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
