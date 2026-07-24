<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

use function array_filter;
use function count;
use function is_callable;
use function is_int;
use function is_string;
use function json_encode;
use function sprintf;
use function uniqid;

use const JSON_PRETTY_PRINT;

class ToolApproval implements WorkflowMiddleware
{
    /**
     * @param array<int|string, string|callable(ToolInterface): bool> $tools Tools that require approval.
     *   - Empty array: all tools require approval (default)
     *   - Numeric key + string value: tool name or class-string always requires approval
     *   - String key + callable value: tool name or class-string with conditional callback.
     *     The callback receives the tool instance and returns bool
     *     (true = requires approval, false = skip).
     *
     * Example:
     *   new ToolApproval([
     *       'delete_file',
     *       'transfer_money' => fn(ToolInterface $tool) => ($tool->getInputs()['amount'] ?? 0) > 100,
     *   ])
     */
    public function __construct(
        protected array $tools = []
    ) {
    }

    /**
     * Execute before the node runs.
     *
     * On initial run: inspects tools and creates an ApprovalRequest interrupt for
     * any that require approval.
     * On resume: processes the human decisions carried in the inbound wake and
     * modifies tools accordingly.
     *
     * The wake for an approval resume is keyed by tool callId:
     *   ['<callId>' => 'approve' | 'reject' | ['reject', $reason] | ['edit', $args]]
     * A resume for a different reason (e.g. an interrupting tool) carries no such
     * keys, so we discriminate by re-deriving the approval-requiring tools
     * (deterministic) and checking their callIds appear in the wake.
     *
     * @param ToolNode $node
     * @param ToolCallEvent $event
     * @throws WorkflowInterrupt
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$event instanceof ToolCallEvent) {
            return;
        }

        $toolsToApprove = $this->filterToolsRequiringApproval($event->toolCallMessage->getTools());

        // Resume: if the wake carries decisions for the approval-requiring tools,
        // apply them. Otherwise this resume is for a different reason — fall through
        // to the initial-run logic (which will re-suspend if approval is still owed).
        if ($node->isResuming() && $toolsToApprove !== []) {
            $wake = $node->getWake() ?? [];
            if ($this->isApprovalResume($wake, $toolsToApprove)) {
                $this->processDecisions($wake, $event);
                return;
            }
        }

        // Initial run (or resume that didn't deliver approval decisions): nothing
        // requires approval, continue execution.
        if ($toolsToApprove === []) {
            return;
        }

        // Create the interrupt request with actions for each tool
        $actions = [];
        foreach ($toolsToApprove as $tool) {
            $actions[] = $this->createAction($tool);
        }

        $count = count($actions);
        $interruptRequest = new ApprovalRequest(
            message: sprintf(
                '%d tool call%s require%s approval before execution',
                $count,
                $count === 1 ? '' : 's',
                $count === 1 ? 's' : ''
            ),
            actions: $actions
        );

        throw new WorkflowInterrupt($interruptRequest);
    }

    /**
     * Is this wake an approval resume? True when it carries a decision for at
     * least one tool that requires approval (re-derived deterministically).
     *
     * @param ToolInterface[] $toolsToApprove
     */
    protected function isApprovalResume(array $wake, array $toolsToApprove): bool
    {
        foreach ($toolsToApprove as $tool) {
            $callId = $tool->getCallId();
            if ($callId !== null && array_key_exists($callId, $wake)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute after the node runs.
     */
    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        //
    }

    /**
     * Filter tools that require approval based on configuration.
     *
     * @param ToolInterface[] $tools
     * @return ToolInterface[]
     */
    protected function filterToolsRequiringApproval(array $tools): array
    {
        if ($this->tools === []) {
            // Empty array means all tools require approval
            return $tools;
        }

        return array_filter(
            $tools,
            $this->toolRequiresApproval(...)
        );
    }

    /**
     * Determine if a specific tool requires approval.
     *
     * Checks the tool against the configured tools list, handling both
     * unconditional (string) and conditional (callable) entries.
     */
    protected function toolRequiresApproval(ToolInterface $tool): bool
    {
        $toolName = $tool->getName();
        $toolClass = $tool::class;

        foreach ($this->tools as $key => $value) {
            // Numeric key + string value: unconditional approval
            if (is_int($key) && is_string($value) && ($value === $toolName || $value === $toolClass)) {
                return true;
            }

            // String key + callable value: conditional approval
            if (is_string($key) && is_callable($value) && ($key === $toolName || $key === $toolClass)) {
                return $value($tool);
            }
        }

        return false;
    }

    /**
     * Create an Action for a tool that requires approval.
     */
    protected function createAction(ToolInterface $tool): Action
    {
        $inputs = $tool->getInputs();
        $inputsDescription = $inputs === []
            ? '(no arguments)'
            : json_encode($inputs, JSON_PRETTY_PRINT);

        return new Action(
            id: $tool->getCallId() ?? uniqid('tool_'),
            name: $tool->getName(),
            description: $inputsDescription,
            decision: ActionDecision::Pending
        );
    }

    /**
     * Process human decisions from the wake and modify tools accordingly.
     *
     * Iterates the tools, looks up each tool's decision in the wake (keyed by
     * callId), and:
     *  - Rejected: the tool result is set to a rejection message (so it won't run).
     *  - Approved / edited / absent: no change — the tool executes normally.
     *
     * @param array<string, mixed> $wake Decisions keyed by tool callId.
     */
    protected function processDecisions(array $wake, ToolCallEvent $event): void
    {
        foreach ($event->toolCallMessage->getTools() as $tool) {
            $callId = $tool->getCallId();
            if ($callId === null) {
                continue;
            }

            if (!array_key_exists($callId, $wake)) {
                // No decision delivered for this tool — treat as approved.
                continue;
            }

            $reason = $this->extractRejectionReason($wake[$callId]);
            if ($reason !== null) {
                $this->handleRejectedTool($tool, $reason);
            }
        }
    }

    /**
     * Decode a wake decision into a rejection reason, or null when the decision
     * is not a rejection.
     *
     * @param mixed $decision 'approve' | 'reject' | ['reject', $reason] | ['edit', $args]
     */
    protected function extractRejectionReason(mixed $decision): ?string
    {
        if ($decision === 'reject') {
            return 'No specific instruction provided.';
        }

        if (is_array($decision) && ($decision[0] ?? null) === 'reject') {
            return isset($decision[1]) && is_string($decision[1])
                ? $decision[1]
                : 'No specific instruction provided.';
        }

        return null;
    }

    /**
     * Handle a rejected tool by setting a rejection message as its result.
     *
     * This prevents the tool from executing its actual logic and instead
     * returns a human-readable rejection message that the AI can process.
     */
    protected function handleRejectedTool(ToolInterface $tool, string $feedback): void
    {
        $rejectionMessage = sprintf(
            "TOOL NOT EXECUTED. The user rejected this action. User instruction: %s. Do not attempt this tool again. Follow the user's instruction or reconsider your plan.",
            $feedback
        );

        $tool->setResult($rejectionMessage);
    }
}
