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
use function array_key_exists;
use function count;
use function is_array;
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
     * A tool runs only if it is explicitly approved (ADR 0002). This gate is stateless: on every
     * resume it re-derives the approval-requiring tools (deterministic, via replay) and demands a
     * COMPLETE decision set in the inbound payload:
     *
     *   ['<callId>' => 'approve' | 'reject' | ['reject', $reason]]
     *
     * - Initial run: suspend with an ApprovalRequest for every approval-requiring tool.
     * - Resume, all decided: apply the decisions and let the node proceed.
     * - Resume, some undecided: re-suspend. The new request reflects the decisions already
     *   delivered (decided actions carry their decision), so the human sees progress rather than a
     *   blank slate. Because the gate is stateless, the payload must be cumulative — it re-states
     *   every prior decision; ApprovalRequest::generatePayload() produces exactly that.
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

        // Nothing requires approval — let the node run.
        if ($toolsToApprove === []) {
            return;
        }

        if ($node->isResuming()) {
            $payload = $node->getResumePayload() ?? [];

            if ($this->undecidedTools($payload, $toolsToApprove) === []) {
                // Complete decision set — apply it and proceed.
                $this->processDecisions($payload, $event);
                return;
            }

            // Incomplete — re-suspend, reflecting the decisions already delivered.
            throw new WorkflowInterrupt($this->buildRequest($payload, $toolsToApprove));
        }

        // Initial run — suspend for all approval-requiring tools.
        throw new WorkflowInterrupt($this->buildRequest([], $toolsToApprove));
    }

    /**
     * The approval-requiring tools that lack a decision in the payload. An empty result means the
     * decision set is complete.
     *
     * @param ToolInterface[] $toolsToApprove
     * @return ToolInterface[]
     */
    protected function undecidedTools(array $payload, array $toolsToApprove): array
    {
        return array_filter(
            $toolsToApprove,
            fn (ToolInterface $tool): bool => !array_key_exists($tool->getCallId() ?? '', $payload)
        );
    }

    /**
     * Build the outbound ApprovalRequest for the given tools, marking each action with the decision
     * already carried in the payload (so a re-suspend shows progress).
     *
     * @param ToolInterface[] $tools
     */
    protected function buildRequest(array $payload, array $tools): ApprovalRequest
    {
        $actions = [];
        foreach ($tools as $tool) {
            $actions[] = $this->createAction($tool, $payload);
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
     * Create an Action for a tool that requires approval. When a payload is provided (a re-suspend),
     * the action carries the decision already delivered for this tool so the request reflects
     * progress.
     *
     * @param array<string, mixed> $payload
     */
    protected function createAction(ToolInterface $tool, array $payload = []): Action
    {
        $id = $tool->getCallId() ?? uniqid('tool_');

        $inputs = $tool->getInputs();
        $inputsDescription = $inputs === []
            ? '(no arguments)'
            : json_encode($inputs, JSON_PRETTY_PRINT);

        $decision = ActionDecision::Pending;
        $feedback = null;
        if (array_key_exists($id, $payload)) {
            [$decision, $feedback] = $this->decodeDecision($payload[$id]);
        }

        return new Action(
            id: $id,
            name: $tool->getName(),
            description: $inputsDescription,
            decision: $decision,
            feedback: $feedback
        );
    }

    /**
     * Map a payload decision back onto an ActionDecision, for re-suspend progress marking.
     *
     * @param mixed $decision 'approve' | 'reject' | ['reject', $reason]
     * @return array{0: ActionDecision, 1: string|null}
     */
    protected function decodeDecision(mixed $decision): array
    {
        if ($decision === 'approve') {
            return [ActionDecision::Approved, null];
        }

        if ($decision === 'reject') {
            return [ActionDecision::Rejected, null];
        }

        if (is_array($decision) && ($decision[0] ?? null) === 'reject') {
            return [
                ActionDecision::Rejected,
                isset($decision[1]) && is_string($decision[1]) ? $decision[1] : null,
            ];
        }

        return [ActionDecision::Pending, null];
    }

    /**
     * Process human decisions from the payload and modify tools accordingly.
     *
     * Iterates the tools, looks up each tool's decision in the payload (keyed by callId), and:
     *  - Rejected: the tool result is set to a rejection message (so it won't run).
     *  - Approved / absent: no change — the tool executes normally.
     *
     * Only reached once the decision set is complete (see before()), so an absent entry here is a
     * tool that does not require approval — running it is correct.
     *
     * @param array<string, mixed> $payload Decisions keyed by tool callId.
     */
    protected function processDecisions(array $payload, ToolCallEvent $event): void
    {
        foreach ($event->toolCallMessage->getTools() as $tool) {
            $callId = $tool->getCallId();
            if ($callId === null) {
                continue;
            }

            if (!array_key_exists($callId, $payload)) {
                // Not an approval-requiring tool — run normally.
                continue;
            }

            $reason = $this->extractRejectionReason($payload[$callId]);
            if ($reason !== null) {
                $this->handleRejectedTool($tool, $reason);
            }
        }
    }

    /**
     * Decode a payload decision into a rejection reason, or null when the decision is not a
     * rejection.
     *
     * @param mixed $decision 'approve' | 'reject' | ['reject', $reason]
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
