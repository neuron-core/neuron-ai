<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

interface NodeInterface
{
    public function run(Event $event, WorkflowState $state): \Generator|Event;

    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        bool $isResuming = false,
        ?Interrupt\InterruptRequest $resumeRequest = null
    ): void;

    /**
     * Check if the node is resuming after an interrupt.
     */
    public function isResuming(): bool;

    /**
     * Get the interrupt request when resuming.
     * Returns null if not resuming or no request provided.
     */
    public function getResumeRequest(): ?Interrupt\InterruptRequest;

    /**
     * Get node checkpoints for persistence.
     *
     * @return array<string, mixed>
     */
    public function getCheckpoints(): array;

    /**
     * Set node checkpoints when resuming.
     *
     * @param array<string, mixed> $checkpoints
     */
    public function setCheckpoints(array $checkpoints): void;
}
