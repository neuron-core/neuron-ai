<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

use function array_filter;
use function array_key_exists;
use function count;
use function end;
use function is_array;
use function is_callable;
use function is_int;
use function is_string;
use function json_encode;
use function sprintf;
use function uniqid;

use const JSON_PRETTY_PRINT;

/**
 * Human-in-the-loop approval gate for tool execution (ADR 0004 / ADR 0006).
 *
 * The gate is stateless: on every pass the middleware decides which tools are gated,
 * marks them pending, applies the CUMULATIVE resume payload — the full decision set,
 * restated on every resume; accumulation lives with the caller — and proceeds only
 * when every gated tool is decided. A tool runs iff explicitly approved; an incomplete
 * decision set re-suspends, and undelivered partial decisions are deliberately not
 * persisted anywhere.
 *
 * Chat history stays append-only: the annotated ToolCallMessage (pending states +
 * resume token) is written once at suspend time so a cold process can render pending
 * approvals from history alone; the final outcomes are recorded on the
 * ToolResultMessage that follows it in the conversation.
 */
class ToolApproval extends AgentMiddleware
{
    /**
     * @param array<int|string, string|callable(ToolInterface): bool|string> $tools Tool approval configuration.
     *   - Empty array (default): each tool decides for itself via requiresApproval().
     *     (BREAKING: this previously meant "all tools require approval".)
     *   - Numeric key + string value (tool name or class-string): that tool ALWAYS
     *     requires approval — overrides the tool's own declaration.
     *   - String key (tool name or class-string) + callable value
     *     fn(ToolInterface $tool): bool|string: the callback decides — overrides the
     *     tool's own declaration in BOTH directions (may waive a tool that declares
     *     true). Returning a string counts as true and doubles as the approval
     *     reason shown to the approver.
     *   - A tool matching no config entry falls back to its own requiresApproval().
     *
     * Example:
     *   new ToolApproval([
     *       DeleteFile::class,
     *       'transfer_money' => fn(ToolInterface $tool) => ($tool->getInputs()['amount'] ?? 0) > 100
     *           ? 'Transfers above $100 require a human sign-off'
     *           : false,
     *   ])
     */
    public function __construct(
        protected array $tools = []
    ) {
    }

    /**
     * Execute before the node runs.
     *
     * @throws WorkflowInterrupt
     */
    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state): void
    {
        if (!$event instanceof ToolCallEvent) {
            return;
        }

        $toolsToApprove = $this->filterToolsRequiringApproval($event->toolCallMessage->getTools());

        // Nothing requires approval — let the node run.
        if ($toolsToApprove === []) {
            return;
        }

        // Every gated tool starts out pending; the cumulative resume payload restates
        // the full decision set on every resume (ADR 0006) — nothing is restored from
        // history or any other store.
        $this->initializePending($toolsToApprove);

        if ($node->isResuming()) {
            $this->mergePayload($node->getResumePayload() ?? [], $toolsToApprove);
        }

        // The resume token (ADR 0005) makes history alone sufficient to resume.
        $workflowId = $state->get('__workflowId');
        if (is_string($workflowId) && $workflowId !== '') {
            $event->toolCallMessage->setResumeToken($workflowId);
        }

        // Persist the annotated message (pending states + resume token) before any
        // suspend, so a cold process can render the pending approvals from history
        // alone. The history is append-only (ADR 0006): the write happens once, on the
        // first pass — on resume passes the message already sits at the tail and stays
        // untouched. Final outcomes reach the conversation record on the
        // ToolResultMessage that follows.
        $chatHistory = $node->getChatHistory();
        $messages = $chatHistory->getMessages();
        $tail = end($messages);
        if ($tail === false || !$event->toolCallMessage->isSameToolCall($tail)) {
            $chatHistory->addMessage($event->toolCallMessage);
        }

        // A tool runs iff explicitly approved; silence is never consent.
        $pending = array_filter(
            $toolsToApprove,
            fn (ToolInterface $tool): bool => $tool->getApprovalState() === ApprovalState::Pending
        );

        if ($pending !== []) {
            throw new WorkflowInterrupt($this->buildRequest($toolsToApprove));
        }

        // Complete decision set: stamp rejection results (rejected tools are skipped by
        // the node), then let it execute — approved and non-gated tools run.
        foreach ($toolsToApprove as $tool) {
            if ($tool->getApprovalState() === ApprovalState::Rejected) {
                $this->handleRejectedTool($tool);
            }
        }
    }

    /**
     * A silently skipped approval gate is a safety hazard — fail loudly.
     */
    protected function onAgentContextMismatch(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        throw new AgentException(sprintf(
            'ToolApproval requires an agent node and AgentState, got %s with %s.',
            $node::class,
            $state::class,
        ));
    }

    /**
     * Stamp every gated tool that still has null state as Pending.
     *
     * @param ToolInterface[] $tools
     */
    protected function initializePending(array $tools): void
    {
        foreach ($tools as $tool) {
            if ($tool->getApprovalState() === null) {
                $tool->setApprovalState(ApprovalState::Pending);
            }
        }
    }

    /**
     * Apply the cumulative inbound payload onto the gated tools. The payload is the
     * entire decision set — every resume restates all decisions, and the latest
     * delivery wins. Entries for unknown callIds (or malformed decisions) are ignored.
     *
     * @param array<string, mixed> $payload  Decisions keyed by callId.
     * @param ToolInterface[]      $toolsToApprove
     */
    protected function mergePayload(array $payload, array $toolsToApprove): void
    {
        $byCallId = [];
        foreach ($toolsToApprove as $tool) {
            $id = $tool->getCallId();
            if ($id !== null) {
                $byCallId[$id] = $tool;
            }
        }

        foreach ($payload as $callId => $decision) {
            if (!is_string($callId)) {
                continue;
            }
            if (!array_key_exists($callId, $byCallId)) {
                continue;
            }
            $tool = $byCallId[$callId];

            if ($decision === 'approve') {
                $tool->setApprovalState(ApprovalState::Approved);
                continue;
            }

            if ($decision === 'reject') {
                $tool->setApprovalState(ApprovalState::Rejected);
                continue;
            }

            if (is_array($decision) && ($decision[0] ?? null) === 'reject') {
                $reason = isset($decision[1]) && is_string($decision[1]) ? $decision[1] : null;
                $tool->setApprovalState(ApprovalState::Rejected, $reason);
            }
            // Anything else: ignore the entry, leave the current state.
        }
    }

    /**
     * Build the outbound ApprovalRequest snapshot from the current tool states. The
     * request is outbound-only (ADR 0001); the inbound decisions travel as a payload.
     *
     * @param ToolInterface[] $tools
     */
    protected function buildRequest(array $tools): ApprovalRequest
    {
        $actions = [];
        foreach ($tools as $tool) {
            $inputs = $tool->getInputs();

            $actions[] = new Action(
                id: $tool->getCallId() ?? uniqid('tool_'),
                name: $tool->getName(),
                description: $inputs === []
                    ? '(no arguments)'
                    : json_encode($inputs, JSON_PRETTY_PRINT),
                decision: $this->mapDecision($tool->getApprovalState()),
                feedback: $tool->getRejectReason(),
                reason: $tool->getApprovalReason(),
            );
        }

        $count = count($actions);

        return new ApprovalRequest(
            message: sprintf(
                '%d tool call%s require%s approval before execution',
                $count,
                $count === 1 ? '' : 's',
                $count === 1 ? 's' : ''
            ),
            actions: $actions
        );
    }

    protected function mapDecision(?ApprovalState $state): ActionDecision
    {
        return match ($state) {
            ApprovalState::Approved => ActionDecision::Approved,
            ApprovalState::Rejected => ActionDecision::Rejected,
            default => ActionDecision::Pending,
        };
    }

    /**
     * Handle a rejected tool by setting a rejection message as its result.
     *
     * The node skips execution for rejected tools (Phase 4); this result is what flows
     * back to the model in place of the tool's real output.
     */
    protected function handleRejectedTool(ToolInterface $tool): void
    {
        $feedback = $tool->getRejectReason() ?? 'No specific instruction provided.';

        $tool->setResult(sprintf(
            "TOOL NOT EXECUTED. The user rejected this action. User instruction: %s. Do not attempt this tool again. Follow the user's instruction or reconsider your plan.",
            $feedback
        ));
    }

    /**
     * Filter tools that require approval based on configuration, falling back to each
     * tool's own self-declaration. A string decision counts as true and is stamped on
     * the tool as its approval reason — the outbound "why am I asking" surfaced to the
     * approver via the ApprovalRequest actions and the tool entries in chat history.
     *
     * @param ToolInterface[] $tools
     * @return ToolInterface[]
     */
    protected function filterToolsRequiringApproval(array $tools): array
    {
        return array_filter(
            $tools,
            function (ToolInterface $tool): bool {
                $decision = $this->toolRequiresApproval($tool);

                if (is_string($decision)) {
                    $tool->setApprovalReason($decision);
                    return true;
                }

                return $decision;
            }
        );
    }

    /**
     * Determine if a specific tool requires approval. A string return means "yes,
     * and this is the reason to show the approver".
     *
     * Middleware configuration overrides the tool's self-declaration in both directions;
     * a tool matching no config entry falls back to its own requiresApproval().
     */
    protected function toolRequiresApproval(ToolInterface $tool): bool|string
    {
        $toolName = $tool->getName();
        $toolClass = $tool::class;

        foreach ($this->tools as $key => $value) {
            // Numeric key + string value: unconditional approval override.
            if (is_int($key) && is_string($value) && ($value === $toolName || $value === $toolClass)) {
                return true;
            }

            // String key + callable value: conditional override.
            if (is_string($key) && is_callable($value) && ($key === $toolName || $key === $toolClass)) {
                return $value($tool);
            }
        }

        // No config match — let the tool decide for itself (ADR 0004).
        return $tool->requiresApproval($tool->getInputs());
    }

}
