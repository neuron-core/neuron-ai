<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use Closure;
use Generator;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

class ToolApprovalMiddleware implements WorkflowMiddleware
{
    /**
     * Feedback received from the human approver.
     *
     * @var array{approved: bool, reason?: string}|null
     */
    protected ?array $feedback = null;

    /**
     * @param string[] $toolsRequiringApproval Tool names that require approval (empty = all tools)
     */
    public function __construct(
        /**
         * Tools that require approval before execution.
         * If empty, all tools require approval.
         */
        protected array $toolsRequiringApproval = []
    )
    {
    }

    /**
     * Handle tool call events with approval workflow.
     *
     * @throws WorkflowInterrupt
     */
    public function handle(Event $event, WorkflowState $state, Closure $next): Event|Generator
    {
        // Interrupt workflow for human approval
    }

    /**
     * Determine if this middleware should handle the given event.
     */
    public function shouldHandle(Event $event): bool
    {
        return $event instanceof ToolCallEvent;
    }
}
