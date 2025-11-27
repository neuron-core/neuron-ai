<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use Generator;

interface NodeInterface
{
    public function run(Event $event, WorkflowState $state): Generator|Event;

    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        bool $isResuming = false,
        ?Interrupt\InterruptRequest $resumeRequest = null
    ): void;

    /**
     * Check if the node is in resuming mode.
     *
     * This is useful for middleware to determine if the workflow is resuming
     * from an interruption.
     */
    public function isResuming(): bool;

    /**
     * Get the resume request if the node is resuming.
     *
     * This allows middleware to access user decisions when resuming from
     * an interruption.
     *
     * @return InterruptRequest|null The resume request or null if not resuming
     */
    public function getResumeRequest(): ?InterruptRequest;
}
