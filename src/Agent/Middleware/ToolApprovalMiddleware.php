<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use Closure;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

/**
 * Middleware that implements human-in-the-loop approval for tool execution.
 *
 * This middleware intercepts tool call events and requests human approval
 * before allowing the tools to execute. It's useful for:
 * - Dangerous operations (file deletion, command execution)
 * - Sensitive data access
 * - High-cost operations (API calls, database modifications)
 * - Compliance requirements (audit trails, approval workflows)
 *
 * Example usage:
 * ```php
 * $agent = Agent::make()
 *     ->setAiProvider($provider)
 *     ->addTool([new FileSystemToolkit(), new ShellToolkit()])
 *     ->middleware(
 *         ToolCallEvent::class,
 *         new ToolApprovalMiddleware(['delete_file', 'execute_command'])
 *     );
 *
 * try {
 *     $response = $agent->chat(UserMessage::make('Delete old logs'));
 * } catch (WorkflowInterrupt $interrupt) {
 *     // Present tools to user for approval
 *     $tools = $interrupt->getData()['tools'];
 *     $approved = promptUserForApproval($tools);
 *
 *     // Store feedback in state and resume
 *     $state = $interrupt->getState();
 *     $state->set('tool_approval_feedback', [
 *         'approved' => $approved,
 *         'reason' => $approved ? null : 'User rejected the operation'
 *     ]);
 *
 *     // Resume workflow (feedback is in state)
 *     $response = $agent->chat($message); // Will resume from interrupt
 * }
 * ```
 */
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
        if (!$event instanceof ToolCallEvent) {
            return $next($event);
        }

        $toolCallMessage = $event->toolCallMessage;
        $tools = $toolCallMessage->getTools();

        // Filter tools that require approval
        $toolsNeedingApproval = \array_filter(
            $tools,
            fn (ToolInterface $tool): bool => $this->requiresApproval($tool->getName())
        );

        // If no tools need approval, proceed normally
        if ($toolsNeedingApproval === []) {
            return $next($event);
        }

        // Check if we're resuming with decisions
        if ($state->has('_tool_approval_resume_request')) {
            $request = $state->get('_tool_approval_resume_request');
            $state->delete('_tool_approval_resume_request');

            // Check if any actions were rejected
            if ($request->hasRejections()) {
                if ($state instanceof AgentState) {
                    $rejectedActions = $request->getRejectedActions();
                    $reasons = \array_map(fn ($action) => $action->feedback ?? 'Rejected', $rejectedActions);
                    $message = 'Tool execution denied: ' . \implode(', ', $reasons);
                    $state->getChatHistory()->addMessage(new UserMessage($message));
                }

                // Go back to the AI provider
                return new StartEvent();
            }

            // All approved - continue to tool execution
            return $next($event);
        }

        // First time seeing this event - create approval actions
        $actions = [];
        /** @var ToolInterface $tool */
        foreach ($toolsNeedingApproval as $tool) {
            $actions[] = new Action(
                id: $tool->getCallId(),
                name: $tool->getName(),
                description: \json_encode($tool->getInputs()),
            );
        }

        $request = new InterruptRequest(
            actions: $actions,
            reason: 'The following tools require approval before execution'
        );

        // Store request in state so it's available on resume
        $state->set('_tool_approval_resume_request', $request);

        // Interrupt workflow for human approval
        // The Workflow will automatically fill in the current node class and checkpoints
        throw new WorkflowInterrupt(
            $request,
            '', // Node class - will be filled by Workflow
            [], // Node checkpoints - will be filled by Workflow
            $state,
            $event
        );
    }

    /**
     * Check if a tool requires approval.
     */
    protected function requiresApproval(string $toolName): bool
    {
        // If no specific tools configured, all tools require approval
        if ($this->toolsRequiringApproval === []) {
            return true;
        }

        return \in_array($toolName, $this->toolsRequiringApproval, true);
    }

    /**
     * Determine if this middleware should handle the given event.
     */
    public function shouldHandle(Event $event): bool
    {
        return $event instanceof ToolCallEvent;
    }
}
