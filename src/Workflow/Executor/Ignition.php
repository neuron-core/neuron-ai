<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;

/**
 * The run's trigger envelope: how a run came into existence, persisted so any
 * blank process can continue it. Carries the runId (the generation stamp of
 * the run currently holding the workflow ID — this record IS the generation
 * head) and the start event (the run's cause and the entry key for step replay).
 */
class Ignition
{
    public function __construct(
        public readonly string $runId,
        public readonly Event $startEvent,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'runId' => $this->runId,
            'startEvent' => $this->startEvent,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->runId = $data['runId'];
        $this->startEvent = $data['startEvent'];
    }
}
