<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;

/**
 * The run's trigger envelope: how a run came into existence, persisted so any
 * blank process can continue it. Carries the runId (the generation stamp of
 * the run currently holding the address — this record IS the generation
 * head), the start event (the run's cause and the entry key for step replay),
 * and the workflow's context bag — an opaque section populated and consumed
 * by workflow subclasses via ignitionContext()/applyIgnitionContext(), never
 * interpreted by the engine (the analog of Inngest's `event.user` /
 * Temporal's `memo`).
 */
class Ignition
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $runId,
        public readonly Event $startEvent,
        public readonly array $context = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'version' => 2,
            'runId' => $this->runId,
            'startEvent' => $this->startEvent,
            'context' => $this->context,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->runId = $data['runId'];
        $this->startEvent = $data['startEvent'];
        $this->context = $data['context'] ?? [];
    }
}
