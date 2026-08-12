<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\Serializer;

/**
 * Durable memoizer bound to a single node-execution step, constructed per
 * step by the executor. Memo values live under "{stepId}::{name}" in the
 * run's partition: the first call runs the operation and persists the value,
 * a replay returns the record without re-running. A memo record is by
 * construction a completed value — interrupted/failed markers only ever
 * live under plain step ids.
 */
final class StepMemoizer
{
    public function __construct(
        protected PersistenceInterface $persistence,
        protected Serializer $serializer,
        protected string $runId,
        protected string $stepId,
    ) {
    }

    public function memo(string $name, Closure $operation): mixed
    {
        $key = $this->stepId . '::' . $name;

        // Record existence (not the decoded value) is the hit signal, so a
        // memoized null replays as null instead of re-running the operation.
        $recorded = $this->persistence->get($this->runId, $key);
        if ($recorded !== null) {
            return $this->serializer->unserialize($recorded);
        }

        $value = $operation();

        $this->persistence->put($this->runId, $key, $this->serializer->serialize($value));

        return $value;
    }

    /**
     * The read-only counterpart to memo(): the recorded value, or null. Lets
     * a node skip non-replayable work whose terminal value was already
     * persisted (e.g. a non-resumable provider stream); memo() stays the
     * write side.
     */
    public function get(string $name): mixed
    {
        $recorded = $this->persistence->get($this->runId, $this->stepId . '::' . $name);

        return $recorded === null ? null : $this->serializer->unserialize($recorded);
    }
}
