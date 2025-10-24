<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

/**
 * Middleware for human-in-the-loop tool approval with support for:
 * - Approving tool calls
 * - Editing tool inputs before execution
 * - Rejecting tool calls with synthesized error responses
 *
 * This middleware intercepts tool execution and allows humans to review,
 * modify, or reject tool calls before they are executed.
 *
 * Example usage:
 * ```php
 * // Require approval for all tools
 * $agent->middleware(
 *     ToolNode::class,
 *     new ToolApprovalMiddleware()
 * );
 *
 * // Require approval only for specific tools
 * $agent->middleware(
 *     ToolNode::class,
 *     new ToolApprovalMiddleware(['delete_file', 'execute_command'])
 * );
 *
 * // Handling the interrupt
 * try {
 *     $response = $agent->chat($message);
 * } catch (WorkflowInterrupt $interrupt) {
 *     $request = $interrupt->getRequest();
 *     $event = $interrupt->getCurrentEvent();
 *
 *     foreach ($request->actions as $action) {
 *         // Approve
 *         $action->approve('Looks safe to execute');
 *
 *         // Or edit tool inputs before execution
 *         if ($action->name === 'delete_file') {
 *             $action->edit('Changed to safe directory');
 *             // Modify the actual tool inputs
 *             $tool = $this->findToolByCallId($event, $action->id);
 *             $tool->setInputs(['path' => '/safe/directory/file.txt']);
 *         }
 *
 *         // Or reject with explanation
 *         $action->reject('This operation is too dangerous');
 *     }
 *
 *     // Resume with decisions
 *     $response = $agent->start($request)->getResult();
 * }
 *
 * // Helper to find tool by call ID
 * private function findToolByCallId(Event $event, string $callId): ?ToolInterface
 * {
 *     if ($event instanceof ToolCallEvent) {
 *         foreach ($event->toolCallMessage->getTools() as $tool) {
 *             if ($tool->getCallId() === $callId) {
 *                 return $tool;
 *             }
 *         }
 *     }
 *     return null;
 * }
 * ```
 */
class ToolApprovalMiddleware implements WorkflowMiddleware
{
    /**
     * State key for storing tool-to-action mapping.
     */
    private const TOOL_ACTION_MAP_KEY = '__tool_approval_action_map__';

    /**
     * @param string[] $toolsRequiringApproval Tool names that require approval (empty = all tools)
     */
    public function __construct(
        protected array $toolsRequiringApproval = []
    ) {
    }

    /**
     * Execute before the node runs.
     *
     * On initial run: Inspects tools and creates interrupt request for approval.
     * On resume: Processes human decisions and modifies tools accordingly.
     *
     * @throws WorkflowInterrupt
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        // Only handle ToolCallEvent
        if (!$event instanceof ToolCallEvent) {
            return;
        }

        // Check if we're resuming with decisions
        if ($node->isResuming() && $node->getResumeRequest() !== null) {
            $this->processDecisions($node->getResumeRequest(), $event, $state);
            return;
        }

        // Initial run: Check if any tools require approval
        $toolsToApprove = $this->filterToolsRequiringApproval($event->toolCallMessage->getTools());

        if (empty($toolsToApprove)) {
            // No tools require approval, continue execution
            return;
        }

        // Create the interrupt request with actions for each tool
        $actions = [];
        foreach ($toolsToApprove as $tool) {
            $actions[] = $this->createActionForTool($tool);
        }

        $interruptRequest = new InterruptRequest(
            actions: $actions,
            reason: \sprintf(
                '%d tool call%s require%s human approval before execution',
                \count($actions),
                \count($actions) === 1 ? '' : 's',
                \count($actions) === 1 ? 's' : ''
            )
        );

        // Store tool-to-action mapping in state for resume processing
        $this->storeToolActionMapping($state, $event->toolCallMessage->getTools(), $actions);

        throw new WorkflowInterrupt(
            $interruptRequest,
            $node::class,
            $node->getCheckpoints(),
            $state,
            $event
        );
    }

    /**
     * Execute after the node runs.
     *
     * Cleanup: Remove tool-action mapping from state after execution.
     */
    public function after(NodeInterface $node, Event $event, Event|Generator $result, WorkflowState $state): void
    {
        // Clean up state after tool execution
        if ($event instanceof ToolCallEvent && $state->has(self::TOOL_ACTION_MAP_KEY)) {
            $state->delete(self::TOOL_ACTION_MAP_KEY);
        }
    }

    /**
     * Filter tools that require approval based on configuration.
     *
     * @param ToolInterface[] $tools
     * @return ToolInterface[]
     */
    protected function filterToolsRequiringApproval(array $tools): array
    {
        if (empty($this->toolsRequiringApproval)) {
            // Empty array means all tools require approval
            return $tools;
        }

        return \array_filter(
            $tools,
            fn (ToolInterface $tool) => \in_array($tool->getName(), $this->toolsRequiringApproval, true)
        );
    }

    /**
     * Create an Action for a tool that requires approval.
     */
    protected function createActionForTool(ToolInterface $tool): Action
    {
        $inputs = $tool->getInputs();
        $inputsDescription = empty($inputs)
            ? '(no arguments)'
            : \json_encode($inputs, JSON_PRETTY_PRINT);

        return new Action(
            id: $tool->getCallId() ?? \uniqid('tool_'),
            name: $tool->getName(),
            description: \sprintf(
                "Tool: %s\nDescription: %s\nInputs: %s",
                $tool->getName(),
                $tool->getDescription() ?? 'No description',
                $inputsDescription
            ),
            decision: ActionDecision::Pending,
            feedback: null
        );
    }

    /**
     * Store mapping between tool call IDs and action IDs for resume processing.
     *
     * @param ToolInterface[] $tools
     * @param Action[] $actions
     */
    protected function storeToolActionMapping(WorkflowState $state, array $tools, array $actions): void
    {
        $mapping = [];

        // Create map of tool call ID to action ID
        foreach ($tools as $tool) {
            $toolCallId = $tool->getCallId();
            if ($toolCallId !== null) {
                // Find corresponding action
                foreach ($actions as $action) {
                    if ($action->id === $toolCallId) {
                        $mapping[$toolCallId] = $action->id;
                        break;
                    }
                }
            }
        }

        $state->set(self::TOOL_ACTION_MAP_KEY, $mapping);
    }

    /**
     * Process human decisions and modify tools accordingly.
     *
     * This method modifies the tools in-place based on human decisions:
     * - Approved: No changes, tool executes normally
     * - Edited: Tool inputs are modified
     * - Rejected: Tool callback is replaced to return rejection message
     */
    protected function processDecisions(
        InterruptRequest $request,
        ToolCallEvent $event,
        WorkflowState $state
    ): void {
        $mapping = $state->get(self::TOOL_ACTION_MAP_KEY, []);

        foreach ($event->toolCallMessage->getTools() as $tool) {
            $toolCallId = $tool->getCallId();

            if ($toolCallId === null || !isset($mapping[$toolCallId])) {
                // Tool doesn't require approval, skip
                continue;
            }

            $action = $request->getAction($mapping[$toolCallId]);

            if ($action === null) {
                // No action found, skip
                continue;
            }

            // Process based on decision
            if ($action->isRejected()) {
                $this->handleRejectedTool($tool, $action);
            } elseif ($action->isEdited()) {
                $this->handleEditedTool($tool, $action);
            }
            // If approved, do nothing - tool executes normally
        }
    }

    /**
     * Handle a rejected tool by replacing its callback with a rejection message.
     *
     * This prevents the tool from executing its actual logic and instead
     * returns a human-readable rejection message that the AI can process.
     */
    protected function handleRejectedTool(ToolInterface $tool, Action $action): void
    {
        $rejectionMessage = \sprintf(
            "Tool '%s' was rejected by human reviewer. Reason: %s",
            $tool->getName(),
            $action->feedback ?? 'No reason provided'
        );

        // Replace the tool's callback with one that returns the rejection message
        $tool->setCallable(function () use ($rejectionMessage): string {
            return $rejectionMessage;
        });
    }

    /**
     * Handle an edited tool by updating its inputs.
     *
     * Note: The Action's feedback should contain instructions on what was edited,
     * but the actual input modification must be done by the application when
     * creating the resume request. This method serves as a placeholder for
     * future enhancements where edited inputs could be stored in the Action.
     *
     * For now, applications should modify tool inputs before setting the decision.
     */
    protected function handleEditedTool(ToolInterface $tool, Action $action): void
    {
        // Currently, edited inputs should be handled by the application
        // before resuming. This method exists for future extensibility
        // where we might store edited inputs in the Action's metadata.

        // In a future version, we could:
        // if (isset($action->metadata['edited_inputs'])) {
        //     $tool->setInputs($action->metadata['edited_inputs']);
        // }
    }
}
