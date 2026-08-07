<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use Throwable;

use function array_filter;
use function array_key_exists;
use function count;
use function is_array;
use function is_string;
use function json_encode;
use function sprintf;
use function uniqid;

use const JSON_PRETTY_PRINT;

/**
 * Node responsible for executing tool calls, including the human-in-the-loop
 * approval flow (ADR 0009).
 *
 * The gate is Tool-centric and stateless: on every pass the node asks each tool
 * whether it requires approval (the tool's own declaration, overridable at attach
 * time — see Tool::requireApproval()/suppressApproval()/withApprovalPolicy()),
 * marks the gated ones pending, and applies the CUMULATIVE resume payload — the
 * full decision set, restated on every resume; accumulation lives with the caller
 * (ADR 0002/0006). A tool runs iff explicitly approved; an incomplete decision set
 * re-suspends, and undelivered partial decisions are deliberately not persisted.
 *
 * Chat history stays append-only with a single writer: when a suspend is possible
 * the annotated ToolCallMessage (pending states + runId) goes through one memoized
 * write before it, so a cold process can render pending approvals from history alone
 * and a resume pass skips the write instead of duplicating the tail. Final outcomes
 * are recorded on the ToolResultMessage that follows it in the conversation.
 *
 * With no gated tools nothing is written here at all: the call/result pair travels
 * as the next inference's inbound messages and commits together through that node's
 * deferred 'history.inbound' write, so a tool crash or a failed follow-up provider
 * call can never leave a dangling tool call in history.
 */
class ToolNode extends Node implements AgentNodeInterface
{
    use ChatHistoryHelper;

    /**
     * Narrowed for static analysis.
     *
     * @var ToolCallEvent
     */
    protected Event $event;

    /**
     * @var callable|null fn(Throwable $e, ToolCall $call): string|ToolOutput|null
     */
    protected $errorHandler;

    public function __construct(
        ChatHistoryInterface $chatHistory,
        protected int $maxRuns = 10,
        ?callable $errorHandler = null
    ) {
        $this->chatHistory = $chatHistory;
        $this->errorHandler = $errorHandler;
    }

    /**
     * @throws ToolRunsExceededException
     * @throws Throwable
     */
    public function __invoke(ToolCallEvent $event, AgentState $state): AIInferenceEvent|Generator
    {
        // Every gated tool starts out pending on every pass — the cumulative resume
        // payload is the sole source of truth for decisions (ADR 0006): a decision
        // that is not restated is not remembered, even on tool instances that
        // survive in memory between passes (InMemoryPersistence aliases stored
        // steps by reference).
        $gated = $this->filterToolsRequiringApproval($event->toolCallMessage->getToolCalls());

        foreach ($gated as $call) {
            $call->setApprovalState(ApprovalState::Pending);
        }

        if ($gated !== []) {
            // The runId makes history alone sufficient to resume (ADR 0005).
            $event->toolCallMessage->setRunId(
                $state->get('__runId') ?? throw new WorkflowException("Missing workflow RUN_ID")
            );

            // The single memoized write of the tool call message (ADR 0009): annotated
            // with pending states and runId BEFORE any suspend, so a cold process renders
            // pending approvals from history alone. On a resume or crash-replay pass the
            // memo skips the write instead of duplicating the tail.
            $this->addToChatHistory($event->toolCallMessage, 'history.toolcall');

            // Settle: a tool runs iff explicitly approved; silence is never consent.
            // The first pass throws the suspend signal; the resume pass receives the
            // cumulative decision set; an incomplete set loops and re-suspends with
            // the delivered decisions reflected on the outbound request.
            while ($this->pendingTools($gated) !== []) {
                $payload = $this->interrupt($this->buildApprovalRequest($gated));
                $this->applyDecisions($payload ?? [], $gated);
            }

            // Complete decision set: stamp rejection results (rejected tools are
            // skipped by executeSingleTool), then approved and non-gated tools run.
            foreach ($gated as $call) {
                if ($call->getApprovalState() === ApprovalState::Rejected) {
                    $this->stampRejectionResult($call);
                }
            }
        }

        $toolCallResult = yield from $this->executeTools($event->toolCallMessage, $state);

        if ($gated === []) {
            // Deferred pair-commit: without an approval suspend there is no reason
            // to persist the tool call before its result exists. The call/result
            // pair travels as the next inference's inbound messages and commits
            // together through the deferred 'history.inbound' write, only after
            // that provider call succeeds — a tool crash or a failed follow-up
            // call leaves the history tail at the last committed message instead
            // of a dangling tool call that wedges the thread.
            $event->inferenceEvent->setMessages($event->toolCallMessage, $toolCallResult);
        } else {
            // The tool call message is already in history (pre-suspend write):
            // only the result message travels as the next turn.
            $event->inferenceEvent->setMessages($toolCallResult);
        }

        // Go back to the AI provider
        return $event->inferenceEvent;
    }

    /**
     * The single source for resolution is the inference event's tool list — the
     * cycle's effective set (base registry plus middleware additions, minus
     * middleware removals). A recalled event arrives here already re-seeded by
     * Workflow::restoreEventNode() (ADR 0010) — the node holds no registry of
     * its own, so a tool removed from the offering is removed from execution.
     */
    protected function findLiveTool(string $name): ToolInterface
    {
        foreach ($this->event->inferenceEvent->tools as $tool) {
            if ($tool instanceof ToolInterface && $tool->getName() === $name) {
                return $tool;
            }
        }

        throw new ToolException(
            "The tool {$name} is not registered on this agent: the call cannot be executed."
        );
    }

    /**
     * Resolve a call against the live tool registry and bind the call data onto a
     * fresh clone (ADR 0010): execution capability never travels with the message,
     * it is re-supplied here. A tool missing from the registry is a loud error —
     * never a silently skipped or dependency-free execution.
     *
     * @throws ToolException
     */
    protected function resolveTool(ToolCall $call): ToolInterface
    {
        $tool = $this->findLiveTool($call->getName());

        $tool = clone $tool;
        $tool->setInputs($call->getInputs());
        if ($call->getCallId() !== null) {
            $tool->setCallId($call->getCallId());
        }

        return $tool;
    }

    /**
     * Filter calls that require approval by asking the LIVE registry tool (ADR
     * 0009/0010: the policy and its attach-time overrides live on the tool; the
     * question is always answered by live capability, so the answer cannot drift
     * across a suspend/resume boundary). A string decision counts as true and is
     * stamped on the call as its approval reason — the outbound "why am I asking"
     * surfaced to the approver via the ApprovalRequest actions and chat history.
     *
     * An unresolvable call is never gated: execution will fail loudly instead.
     *
     * @param ToolCall[] $calls
     * @return ToolCall[]
     * @throws ToolException
     */
    protected function filterToolsRequiringApproval(array $calls): array
    {
        return array_filter(
            $calls,
            function (ToolCall $call): bool {
                try {
                    $this->findLiveTool($call->getName());
                } catch (ToolException) {
                    return false;
                }

                // Ask a clone with the call's inputs bound, so a policy callback
                // reading $tool->getInputs() sees this call's arguments.
                $tool = $this->resolveTool($call);

                $decision = $tool->requiresApproval($call->getInputs());

                if (is_string($decision)) {
                    $call->setApprovalReason($decision);
                    return true;
                }

                return $decision;
            }
        );
    }

    /**
     * @param ToolCall[] $gated
     * @return ToolCall[]
     */
    protected function pendingTools(array $gated): array
    {
        return array_filter(
            $gated,
            fn (ToolCall $call): bool => $call->getApprovalState() === ApprovalState::Pending
        );
    }

    /**
     * Apply the cumulative inbound payload onto the gated tools. The payload is the
     * entire decision set — every resume restates all decisions, and the latest
     * delivery wins. Entries for unknown callIds (or malformed decisions) are ignored.
     *
     * @param array<string, mixed> $payload Decisions keyed by callId.
     * @param ToolCall[]           $gated
     */
    protected function applyDecisions(array $payload, array $gated): void
    {
        $byCallId = [];
        foreach ($gated as $call) {
            $id = $call->getCallId();
            if ($id !== null) {
                $byCallId[$id] = $call;
            }
        }

        foreach ($payload as $callId => $decision) {
            if (!is_string($callId)) {
                continue;
            }
            if (!array_key_exists($callId, $byCallId)) {
                continue;
            }
            $call = $byCallId[$callId];

            if ($decision === 'approve') {
                $call->setApprovalState(ApprovalState::Approved);
                continue;
            }

            if ($decision === 'reject') {
                $call->setApprovalState(ApprovalState::Rejected);
                continue;
            }

            if (is_array($decision) && ($decision[0] ?? null) === 'reject') {
                $reason = isset($decision[1]) && is_string($decision[1]) ? $decision[1] : null;
                $call->setApprovalState(ApprovalState::Rejected, $reason);
            }
            // Anything else: ignore the entry, leave the current state.
        }
    }

    /**
     * Build the outbound ApprovalRequest snapshot from the current tool states. The
     * request is outbound-only (ADR 0001); the inbound decisions travel as a payload.
     *
     * @param ToolCall[] $gated
     * @throws WorkflowException
     */
    protected function buildApprovalRequest(array $gated): ApprovalRequest
    {
        $actions = [];
        foreach ($gated as $call) {
            $inputs = $call->getInputs();

            $actions[] = new Action(
                id: $call->getCallId() ?? uniqid('tool_'),
                name: $call->getName(),
                description: $inputs === []
                    ? '(no arguments)'
                    : json_encode($inputs, JSON_PRETTY_PRINT),
                decision: $this->mapDecision($call->getApprovalState()),
                feedback: $call->getRejectReason(),
                reason: $call->getApprovalReason(),
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
     * executeSingleTool skips execution for rejected tools; this result is what
     * flows back to the model in place of the tool's real output.
     */
    protected function stampRejectionResult(ToolCall $call): void
    {
        $feedback = $call->getRejectReason() ?? 'No specific instruction provided.';

        $call->setResult(sprintf(
            "TOOL NOT EXECUTED. The user rejected this action. User instruction: %s. Do not attempt this tool again. Follow the user's instruction or reconsider your plan.",
            $feedback
        ));
    }

    /**
     * @throws Throwable
     * @throws ToolRunsExceededException
     */
    protected function executeTools(ToolCallMessage $toolCallMessage, AgentState $state): Generator
    {
        foreach ($toolCallMessage->getToolCalls() as $index => $call) {
            yield new ToolCallChunk($call);
            $this->executeSingleTool($call, $state, $index);
            yield new ToolResultChunk($call);
        }

        return new ToolResultMessage($toolCallMessage->getToolCalls());
    }

    /**
     * Execute a single tool with proper error handling and retry logic.
     *
     * The tool execution is wrapped in a durable memo keyed by the tool call id
     * (or name when the provider supplies none) plus the call's position in the
     * message, so parallel calls sharing a callId never share a memo. On replay — when
     * the node re-executes because its step crashed before completing — the recorded
     * result is restored onto the tool WITHOUT re-running it, so side-effecting tools
     * (emails, payments, ...) execute at most once.
     *
     * @throws ToolRunsExceededException If the tool exceeds its maximum retry attempts
     * @throws Throwable If the tool execution fails and no error handler is set
     */
    protected function executeSingleTool(ToolCall $call, AgentState $state, int $index): void
    {
        if ($call->getApprovalState() === ApprovalState::Rejected) {
            // The rejection result was stamped by the approval flow; the tool
            // must not run (ADR 0002/0009: a tool runs iff explicitly approved).
            return;
        }

        $this->emit(new ToolCalling($call));

        $memoKey = 'tool.' . ($call->getCallId() ?? $call->getName()) . '.' . $index;

        try {
            $result = $this->memoize($memoKey, function () use ($call, $state): string|ToolOutput {
                // Resolution happens inside the memo: on replay the recorded result
                // is returned and the live registry is never consulted.
                $tool = $this->resolveTool($call);

                $key = $tool->getRunKey();

                $state->incrementToolRun($key);

                // Single tool max tries have the highest priority over the global max tries
                $runs = $tool->getMaxRuns() ?? $this->maxRuns;
                if ($state->getToolRuns($key) > $runs) {
                    throw new ToolRunsExceededException("Tool {$call->getName()} has been executed too many times - {$runs} - with arguments: ".json_encode($call->getInputs()));
                }

                $tool->execute();
                return $tool->getResult();
            });

            // The result settles onto the call — the conversation record.
            $call->setResult($result);
        } catch (Throwable $e) {
            $this->handleError($e, $call);
        } finally {
            $this->emit(new ToolCalled($call));
        }
    }

    /**
     * Handle an exception that escaped tool execution. Escaped exceptions are
     * bugs and propagate by default — a conversational failure is a RETURNED
     * ToolOutput::error(), never a throw. The error handler is the cross-cutting
     * override: returning a string or ToolOutput settles it as the call's
     * result and the loop continues; returning null declines, and the default
     * policy (propagate) applies.
     *
     * @throws Throwable When no handler is set, or the handler declines
     */
    protected function handleError(Throwable $e, ToolCall $call): void
    {
        $result = $this->errorHandler === null ? null : ($this->errorHandler)($e, $call);

        if ($result === null) {
            throw $e;
        }

        $call->setResult($result);
    }
}
