<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use Generator;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

/**
 * Middleware for human-in-the-loop tool approval.
 *
 * This middleware intercepts tool execution to request human approval before
 * executing specified tools. Useful for sensitive operations like file deletion,
 * database modifications, or external API calls.
 *
 * Usage:
 * ```php
 * // Require approval for specific tools
 * $agent->middleware(ToolNode::class, new ToolApprovalMiddleware(['delete_file', 'execute_command']));
 *
 * // Require approval for all tools
 * $agent->middleware(ToolNode::class, new ToolApprovalMiddleware());
 * ```
 *
 * Handling interrupts:
 * ```php
 * try {
 *     $response = $agent->chat($message);
 * } catch (WorkflowInterrupt $interrupt) {
 *     $tools = $interrupt->getRequest()->getData()['tools'];
 *     $approved = promptUser("Approve these tools?", $tools);
 *
 *     $resumeRequest = InterruptRequest::make('tool_approval')
 *         ->addDecision('approved', $approved)
 *         ->addDecision('reason', $approved ? null : 'User denied');
 *
 *     $response = $agent->start($resumeRequest)->getResult();
 * }
 * ```
 */
class ToolApprovalMiddleware implements WorkflowMiddleware
{
    /**
     * @param string[] $toolsRequiringApproval Tool names that require approval (empty = all tools)
     */
    public function __construct(
        protected array $toolsRequiringApproval = []
    )
    {
    }

    /**
     * Execute before the node runs.
     *
     * Checks if tools require approval and interrupts the workflow if needed.
     *
     * @throws WorkflowInterrupt
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        // Only handle ToolCallEvent
        if (!$event instanceof ToolCallEvent) {
            return;
        }

        $toolCallMessage = $event->toolCallMessage;
        $tools = $toolCallMessage->getTools();

        // Check if any tools require approval
        $toolsNeedingApproval = [];
        foreach ($tools as $tool) {
            $needsApproval = $this->toolsRequiringApproval === []
                || \in_array($tool->getName(), $this->toolsRequiringApproval, true);

            if ($needsApproval) {
                $toolsNeedingApproval[] = [
                    'name' => $tool->getName(),
                    'arguments' => $tool->getArguments(),
                ];
            }
        }

        // No tools need approval
        if ($toolsNeedingApproval === []) {
            return;
        }

        // Check for feedback from previous interrupt (resuming)
        if ($node->isResuming() && $node->getResumeRequest() !== null) {
            $resumeRequest = $node->getResumeRequest();

            if ($resumeRequest->getInterruptName() === 'tool_approval') {
                $approved = $resumeRequest->getDecision('approved', false);

                if (!$approved) {
                    // User denied - we need to stop the workflow
                    // This is tricky because we're in before(), not in the node execution
                    // We'll throw an exception that should be caught by the workflow
                    $reason = $resumeRequest->getDecision('reason', 'User denied tool execution');
                    throw new \RuntimeException("Tool execution denied: {$reason}");
                }

                // Approved - continue execution
                return;
            }
        }

        // No feedback yet - interrupt for approval
        $interruptRequest = InterruptRequest::make('tool_approval')
            ->setData([
                'tools' => $toolsNeedingApproval,
                'message' => 'The following tools require approval before execution',
            ]);

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
     * No action needed after tool execution.
     */
    public function after(NodeInterface $node, Event $event, Event|Generator $result, WorkflowState $state): void
    {
        // No action needed after tool execution
    }
}
