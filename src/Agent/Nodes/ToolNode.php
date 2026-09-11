<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\AwaitToolResultsEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use Throwable;
use function array_diff_key;
use function array_filter;
use function array_key_exists;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function json_encode;
use function ksort;
use function sprintf;
use function uniqid;
use const JSON_PRETTY_PRINT;

/**
 * Executes tool calls, including the human-in-the-loop approval flow.
 *
 * The gate is Tool-centric and stateless: on every pass the node asks each
 * tool whether it requires approval and applies the CUMULATIVE resume payload
 * — the full decision set, restated on every resume; accumulation lives with
 * the caller. A tool runs iff explicitly approved; an incomplete set
 * re-suspends, and partial decisions are deliberately not persisted.
 *
 * Gated and externally executed cycles write their ToolCallMessage once,
 * before suspension. Other cycles commit the call/result pair together
 * through the next inference's inbound write. Approved deferred calls pass
 * to a separate durable step, so awaiting results cannot replay this gate
 * or the completed local executions.
 */
class ToolNode extends Node implements AgentNodeInterface
{
    use ChatHistoryHelper;
    /** @var AgentState */
    protected WorkflowState $state;

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
    public function __invoke(ToolCallEvent $event, AgentState $state): AIInferenceEvent|AwaitToolResultsEvent|Generator
    {
        $approvalGated = $this->resolveToolApprovals($event->toolCallMessage);

        $calls = $event->toolCallMessage->getToolCalls();
        $executed = yield from $this->executeLocalTools($calls);
        $deferred = $this->filterDeferredCalls($calls);

        if ($deferred !== []) {
            // Record each dispatch's count before suspending. State preserves the
            // run total across resumes; replay restores the recorded count without
            // consuming another slot for the same call.
            foreach ($deferred as $index => $call) {
                try {
                    $this->checkToolRuns($call, $index);
                } catch (Throwable $e) {
                    $this->handleError($e, $call);
                    $executed[$index] = $call;
                    unset($deferred[$index]);
                    yield new ToolCallChunk($call);
                    yield new ToolResultChunk($call);
                    $this->emit(new ToolCalled($call));
                }
            }

            if ($deferred !== []) {
                $this->addToChatHistory($event->toolCallMessage, 'history.toolcall');
                foreach ($deferred as $call) {
                    $this->emit(new ToolCalling($call));
                    yield new ToolCallChunk($call);
                }

                // Go to the deferred tool management node
                return new AwaitToolResultsEvent($executed, $deferred);
            }
        }

        ksort($executed);
        $toolCallResult = new ToolResultMessage(array_values($executed));

        if (!$approvalGated) {
            // Deferred pair-commit: the call/result pair travels as the next
            // inference's inbound messages and commits together only after
            // that provider call succeeds — a crash leaves the history tail
            // at the last committed message, never at a dangling tool call.
            $state->request->messages = [$event->toolCallMessage, $toolCallResult];
        } else {
            // The tool call message is already in history (pre-suspend write):
            // only the result message travels as the next turn.
            $state->request->messages = [$toolCallResult];
        }

        return AIInferenceEvent::fromRequest($state->request);
    }

    /**
     * @return bool Whether this cycle required approval.
     * @throws ToolException
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function resolveToolApprovals(ToolCallMessage $message): bool
    {
        // Every gated tool starts out pending on every pass: the cumulative
        // resume payload is the sole source of truth — a decision that is not
        // restated is not remembered, even on tool instances that survive in
        // memory between passes.
        $gated = $this->filterToolsRequiringApproval($message->getToolCalls());

        if ($gated === []) {
            return false;
        }

        foreach ($gated as $call) {
            $call->setApprovalState(ApprovalState::Pending);
        }

        // Written with pending states BEFORE any suspend, so a cold
        // process renders pending approvals from history alone; the memo
        // keeps a resume pass from duplicating the tail.
        $this->addToChatHistory($message, 'history.toolcall');

        // A tool runs if explicitly approved; silence is never consent.
        // An incomplete decision set loops and re-suspends with the
        // delivered decisions reflected on the outbound request.
        while ($this->pendingTools($gated) !== []) {
            $payload = $this->interrupt($this->buildApprovalRequest($gated));
            $this->applyDecisions($payload ?? [], $gated);
        }

        foreach ($gated as $call) {
            if ($call->getApprovalState() === ApprovalState::Rejected) {
                $this->stampRejectionResult($call);
            }
        }

        return true;
    }

    /**
     * @param array<int, ToolCall> $calls
     * @return array<int, ToolCall>
     */
    protected function filterDeferredCalls(array $calls): array
    {
        // Deferred tools can require approval too. Rejected calls keep their
        // rejection result in the local path and are never dispatched externally.
        return array_filter(
            $calls,
            fn (ToolCall $call): bool => $call->isDeferred()
                && $call->getApprovalState() !== ApprovalState::Rejected,
        );
    }

    /**
     * The single source for resolution is the state request's tool list —
     * the cycle's effective set. The node holds no registry of its own, so a
     * tool removed from the offering is removed from execution.
     *
     * @throws ToolException
     */
    protected function findLiveTool(string $name): ToolInterface
    {
        foreach ($this->state->request->tools as $tool) {
            if ($tool instanceof ToolInterface && $tool->getName() === $name) {
                return $tool;
            }
        }

        throw new ToolException(
            "The tool {$name} is not registered on this agent: the call cannot be executed."
        );
    }

    /**
     * Bind the call data onto a fresh clone of the live tool: execution
     * capability never travels with the message, it is re-supplied here.
     * A tool missing from the registry is a loud error, never a silent skip.
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
     * The question is always answered by the LIVE tool, so the answer cannot
     * drift across a suspend/resume boundary. A string decision counts as
     * true and doubles as the approval reason shown to the approver. An
     * unresolvable call is never gated: execution will fail loudly instead.
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
     * The payload is the entire decision set — every resume restates all
     * decisions, the latest delivery wins. Entries for unknown callIds or
     * malformed decisions are ignored.
     *
     * @param array<array-key, mixed> $payload Decisions keyed by callId.
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
            $callId = (string) $callId;
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
     * The request is outbound-only; the inbound decisions travel as a payload.
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
                inputs: $inputs,
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
     * The rejection message flows back to the model in place of the tool's
     * real output (executeSingleTool skips rejected tools).
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
     * Returns locally settled calls, including rejections, with original indexes.
     *
     * @param array<int, ToolCall> $calls
     * @return Generator<int, ToolCallChunk|ToolResultChunk, mixed, array<int, ToolCall>>
     * @throws Throwable
     * @throws ToolRunsExceededException
     */
    protected function executeLocalTools(array $calls): Generator
    {
        $local = array_diff_key($calls, $this->filterDeferredCalls($calls));
        foreach ($local as $index => $call) {
            yield new ToolCallChunk($call);
            $this->executeSingleTool($call, $index);
            yield new ToolResultChunk($call);
        }

        return $local;
    }

    /**
     * Execution is wrapped in a durable memo keyed by callId (or name) plus
     * the call's position, so parallel calls sharing a callId never share a
     * memo. On crash-replay the recorded result is restored WITHOUT
     * re-running, so side-effecting tools execute at most once.
     *
     * @throws ToolRunsExceededException If the tool exceeds its maximum retry attempts
     * @throws Throwable If the tool execution fails and no error handler is set
     */
    protected function executeSingleTool(ToolCall $call, int $index): void
    {
        // A rejected tool must not run; its rejection result was already stamped.
        if ($call->getApprovalState() === ApprovalState::Rejected) {
            return;
        }

        $this->emit(new ToolCalling($call));

        $memoKey = 'tool.' . ($call->getCallId() ?? $call->getName()) . '.' . $index;

        try {
            $this->checkToolRuns($call, $index);
            $result = $this->memoize($memoKey, function () use ($call): string|ToolOutput {
                // Resolution happens inside the memo: on replay the recorded
                // result is returned and the live registry is never consulted.
                $tool = $this->resolveTool($call);

                $tool->execute();
                return $tool->getResult();
            });

            $call->setResult($result);
        } catch (Throwable $e) {
            $this->handleError($e, $call);
        } finally {
            $this->emit(new ToolCalled($call));
        }
    }

    /**
     * Accounting is independent of execution-result memos: a replay must restore
     * counts even when a tool result is already cached. Record before enforcing
     * the limit so a rejected attempt keeps the same decision on recovery.
     *
     * @throws ToolRunsExceededException
     * @throws ToolException
     */
    protected function checkToolRuns(ToolCall $call, int $index): void
    {
        $attempt = $this->memoize('tool_run.' . $index, function () use ($call): array {
            $tool = $this->resolveTool($call);
            $key = $tool->getRunKey();

            return [
                'key' => $key,
                'count' => $this->state->getToolRuns($key) + 1,
                'limit' => $tool->getMaxRuns() ?? $this->maxRuns,
            ];
        });

        $this->state->restoreToolRunCount($attempt['key'], $attempt['count']);
        $runs = $attempt['limit'];
        if ($attempt['count'] > $runs) {
            throw new ToolRunsExceededException("Tool {$call->getName()} has been executed too many times - {$runs} - with arguments: ".json_encode($call->getInputs()));
        }
    }

    /**
     * Escaped exceptions are bugs and propagate by default — a conversational
     * failure is a RETURNED ToolOutput::error(), never a throw. A handler
     * returning a string or ToolOutput settles it as the call's result;
     * returning null declines and the exception propagates.
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
