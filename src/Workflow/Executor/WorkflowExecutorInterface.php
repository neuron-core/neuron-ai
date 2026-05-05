<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowInterface;

interface WorkflowExecutorInterface
{
    /**
     * Run the workflow from the beginning.
     *
     * The executor owns the full lifecycle: bootstrap, start event resolution,
     * node traversal, persistence, error handling, and observability.
     *
     * @return Generator<int, Event, mixed, void>
     */
    public function run(WorkflowInterface $workflow): Generator;

    /**
     * Resume the workflow from a persisted interrupt.
     *
     * @return Generator<int, Event, mixed, void>
     */
    public function resume(WorkflowInterface $workflow, InterruptRequest $request): Generator;

    /**
     * Set the persistence backend for interrupt/resume storage.
     */
    public function setPersistence(PersistenceInterface $persistence): static;

    /**
     * Get the configured persistence backend.
     */
    public function getPersistence(): ?PersistenceInterface;
}
