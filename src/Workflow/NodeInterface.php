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
        array $feedback = []
    ): void;

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
