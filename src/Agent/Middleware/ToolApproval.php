<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ApprovalState;
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
use function array_map;

use const JSON_PRETTY_PRINT;

/**
 * Human-in-the-loop approval gate for tool execution (ADR 0003 / ADR 0004).
 *
 * Approval state lives on the tool entries of the ToolCallMessage persisted in CHAT
 * HISTORY — that is the system of record, not the middleware or the resume payload.
 * The middleware's job on every pass is: decide which tools are gated, restore any
 * previously recorded decisions from history, merge the inbound payload on top, write
 * the annotated message back to history, and proceed only when every gated tool is
 * decided. Decisions are revisable (last-write-wins) until the set completes;
 * completeness is the point of no return.
 */
class ToolApproval implements WorkflowMiddleware
{
    /**
     * @param array<int|string, string|callable(ToolInterface): bool> $tools Tool approval configuration.
     *   - Empty array (default): each tool decides for itself via requiresApproval().
     *     (BREAKING: this previously meant "all tools require approval".)
     *   - Numeric key + string value (tool name or class-string): that tool ALWAYS
     *     requires approval — overrides the tool's own declaration.
     *   - String key (tool name or class-string) + callable value
     *     fn(ToolInterface $tool): bool: the callback decides — overrides the tool's
     *     own declaration in BOTH directions (may waive a tool that declares true).
     *   - A tool matching no config entry falls back to its own requiresApproval().
     *
     * Example:
     *   new ToolApproval([
     *       DeleteFile::class,
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
     * @throws WorkflowInterrupt
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$event instanceof ToolCallEvent) {
            return;
        }

        $toolsToApprove = $this->filterToolsRequiringApproval($event->toolCallMessage->getTools());

        // Nothing requires approval — let the node run.
        if ($toolsToApprove === []) {
            return;
        }

        // The event tools are fresh replayed instances; history is the memory (ADR 0003).
        if ($state instanceof AgentState) {
            $this->restoreRecordedState($state, $event);
        }

        // Every gated tool that still has no recorded state starts out pending.
        $this->initializePending($toolsToApprove);

        // An inbound resume payload carries incremental NEW decisions only.
        if ($node->isResuming()) {
            $this->mergePayload($node->getResumePayload() ?? [], $toolsToApprove);
        }

        // Persist the annotated message. Idempotent by the replace-last rule: the first
        // run appends; every later run (resume) replaces the tail. The history write MUST
        // happen before any suspend so the pending state is durable.
        if ($state instanceof AgentState) {
            $state->getChatHistory()->addMessage($event->toolCallMessage);
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
     * Execute after the node runs.
     */
    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        //
    }

    /**
     * Restore the previously recorded approval state from chat history onto the fresh
     * event tools. Only runs when the tail message is a ToolCallMessage carrying the
     * same ordered callIds as the event (i.e. a resume of the suspended approval step).
     */
    protected function restoreRecordedState(AgentState $state, ToolCallEvent $event): void
    {
        $history = $state->getChatHistory()->getMessages();

        if ($history === []) {
            return;
        }

        $last = end($history);

        if (!$last instanceof ToolCallMessage || !$this->sameCallIds($last, $event->toolCallMessage)) {
            return;
        }

        $recorded = [];
        foreach ($last->getTools() as $recordedTool) {
            $id = $recordedTool->getCallId();
            if ($id !== null) {
                $recorded[$id] = $recordedTool;
            }
        }

        foreach ($event->toolCallMessage->getTools() as $tool) {
            $id = $tool->getCallId();
            if ($id === null) {
                continue;
            }
            if (!array_key_exists($id, $recorded)) {
                continue;
            }

            $recordedState = $recorded[$id]->getApprovalState();
            if ($recordedState instanceof \NeuronAI\Tools\ApprovalState) {
                $tool->setApprovalState($recordedState, $recorded[$id]->getApprovalReason());
            }
        }
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
     * Merge the incremental inbound payload onto the gated tools. Last-write-wins: a
     * payload entry overwrites any previously recorded decision for an undecided-set
     * suspension. Entries for unknown callIds (or malformed decisions) are ignored.
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
                feedback: $tool->getApprovalReason(),
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
        $feedback = $tool->getApprovalReason() ?? 'No specific instruction provided.';

        $tool->setResult(sprintf(
            "TOOL NOT EXECUTED. The user rejected this action. User instruction: %s. Do not attempt this tool again. Follow the user's instruction or reconsider your plan.",
            $feedback
        ));
    }

    /**
     * Filter tools that require approval based on configuration, falling back to each
     * tool's own self-declaration.
     *
     * @param ToolInterface[] $tools
     * @return ToolInterface[]
     */
    protected function filterToolsRequiringApproval(array $tools): array
    {
        return array_filter(
            $tools,
            $this->toolRequiresApproval(...)
        );
    }

    /**
     * Determine if a specific tool requires approval.
     *
     * Middleware configuration overrides the tool's self-declaration in both directions;
     * a tool matching no config entry falls back to its own requiresApproval().
     */
    protected function toolRequiresApproval(ToolInterface $tool): bool
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

    /**
     * Whether two ToolCallMessages carry the same ordered list of callIds.
     */
    protected function sameCallIds(ToolCallMessage $a, ToolCallMessage $b): bool
    {
        $ids = static fn (ToolCallMessage $m): array => array_map(
            static fn (ToolInterface $tool): ?string => $tool->getCallId(),
            $m->getTools()
        );

        return $ids($a) === $ids($b);
    }
}
