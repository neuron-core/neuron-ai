<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\Serializer;

/**
 * Durable memoizer bound to a single node-execution step.
 *
 * Each memo value is serialized directly into the run's partition under the
 * namespaced key "{stepId}::{name}": the first call runs the operation and
 * persists the value; on replay the recorded value is returned without
 * re-running the operation. A memo record is by construction a completed
 * value — interrupted/failed markers only ever live under plain step ids.
 *
 * An instance is constructed per step by the executor (which knows the
 * current stepId and the run's codec) and threaded into the node via
 * setWorkflowContext().
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
     * Recall a previously memoized value WITHOUT running anything.
     *
     * Returns the recorded value when a memo exists for this name (typically
     * a prior run's recovery), or null otherwise. Call it before the matching
     * memo() so a fresh run sees nothing and executes the work.
     *
     * This is the read-only counterpart to memo(): it lets a node skip
     * non-replayable work whose terminal value was already persisted — e.g. a
     * streaming node recalling a completed provider response instead of
     * re-opening a non-resumable stream. memo() handles the write side.
     */
    public function get(string $name): mixed
    {
        $recorded = $this->persistence->get($this->runId, $this->stepId . '::' . $name);

        return $recorded === null ? null : $this->serializer->unserialize($recorded);
    }
}
