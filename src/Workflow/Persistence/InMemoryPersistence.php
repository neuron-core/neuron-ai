<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;

class InMemoryPersistence implements PersistenceInterface
{
    /**
     * Serialized snapshots keyed by runId then stepId. Round-tripping through
     * serialize() gives InMemory the same snapshot semantics as the durable
     * backends: the stored value is a detached copy (mutations to the live
     * StepResult after save are not visible on load), and PHP fires the state's
     * __serialize/__unserialize pair — so transient accumulators such as
     * AgentState::$__steps are stripped exactly as they are on File/Database.
     */
    /** @var array<string, array<string, string>> */
    protected array $storage = [];

    public function save(string $runId, string $stepId, StepResult $result): void
    {
        $this->storage[$runId][$stepId] = serialize($result);
    }

    public function load(string $runId, string $stepId): ?StepResult
    {
        if (!isset($this->storage[$runId][$stepId])) {
            return null;
        }

        return unserialize($this->storage[$runId][$stepId]);
    }

    public function delete(string $runId): void
    {
        unset($this->storage[$runId]);
    }
}
