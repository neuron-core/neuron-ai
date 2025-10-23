<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use Closure;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Event;
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
     * Whether the middleware is resuming after an interruption.
     */
    protected bool $isResuming = false;

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
            fn (\NeuronAI\Tools\ToolInterface $tool): bool => $this->requiresApproval($tool->getName())
        );

        // If no tools need approval, proceed normally
        if ($toolsNeedingApproval === []) {
            return $next($event);
        }

        // Check if we have feedback (resuming after interruption)
        if ($state->has('tool_approval_feedback')) {
            $feedback = $state->get('tool_approval_feedback');
            $state->delete('tool_approval_feedback');

            // If not approved, skip tool execution and go back to AI
            if (!($feedback['approved'] ?? false)) {
                if ($state instanceof AgentState) {
                    $reason = $feedback['reason'] ?? 'User denied tool execution';
                    $state->getChatHistory()->addMessage(
                        new UserMessage($reason)
                    );
                }

                // Skip tool execution, go back to AI provider
                return new StartEvent();
            }

            // Approved - continue to tool execution
            return $next($event);
        }

        // First time seeing this event - request approval
        $toolsData = \array_map(
            fn ($tool): array => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'arguments' => $tool->getArguments(),
            ],
            $toolsNeedingApproval
        );

        // Interrupt workflow for human approval
        // The Workflow will automatically fill in the current node class and checkpoints
        throw new WorkflowInterrupt(
            [
                'type' => 'tool_approval_required',
                'message' => 'The following tools require approval before execution:',
                'tools' => $toolsData,
                'tool_count' => \count($toolsNeedingApproval),
            ],
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
