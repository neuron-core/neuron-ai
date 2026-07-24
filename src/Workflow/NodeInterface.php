<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\StepMemoizer;
use Generator;

interface NodeInterface
{
    public function run(Event $event, WorkflowState $state): Generator|Event;

    /**
     * @param array<string, mixed>|null $wake The inbound resume wake, or null when not resuming.
     */
    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        ?array $wake = null,
        bool $timedOut = false,
        ?StepMemoizer $memoizer = null,
    ): void;

    /**
     * Check if the node is in resuming mode.
     *
     * This is useful for middleware to determine if the workflow is resuming
     * from an interruption.
     */
    public function isResuming(): bool;

    /**
     * The inbound resume wake, or null when not resuming. Read by middleware
     * (e.g. ToolApproval) to access the delivered answer.
     *
     * @return array<string, mixed>|null
     */
    public function getWake(): ?array;
}
