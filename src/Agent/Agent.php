<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Closure;
use Generator;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsTranslator;
use NeuronAI\Agent\Nodes\AwaitToolResultsNode;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\AgentStartNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function array_filter;
use function array_values;
use function end;
use function is_array;
use function is_string;

/**
 * @extends Workflow<AgentState>
 * @method static static make(?string $workflowId = null, ?AgentState $state = null, ?string $threadId = null)
 * @method AgentStartEvent getStartEvent()
 * @method static setStreamAdapter(?StreamAdapterInterface $adapter) Configure Workflow-owned stream adaptation.
 */
class Agent extends Workflow implements AgentInterface
{
    use HandleProvider;
    use HandleTools;
    use HandleInstructions;

    protected ChatHistoryInterface $chatHistory;

    /**
     * The conversation this run belongs to, and the run's declared workflow
     * ID. Adopted from configuration or persistence, and NEVER generated —
     * identity is always a developer statement. Null means the run is not
     * findable by its thread.
     */
    protected ?string $threadId = null;

    protected bool $parallelToolCalls = false;

    protected ?Closure $beforeParallelToolChild = null;

    protected ?Closure $afterParallelToolChild = null;

    protected bool $executing = false;

    /**
     * @throws WorkflowException
     * @throws AgentException
     */
    public function __construct(
        ?string $workflowId = null,
        ?AgentState $state = null,
        ?string $threadId = null,
    ) {
        parent::__construct($workflowId, $state);

        if ($threadId !== null) {
            $this->adoptThreadId($threadId);
        }
    }

    /**
     * Determines whether tools should be executed in parallel and optionally
     * configures callbacks to initialize and clean up resources in each child process.
     *
     * Note: Parallel execution requires the pcntl extension and spatie/fork package.
     */
    public function parallelToolCalls(
        bool $enabled = true,
        ?callable $beforeChild = null,
        ?callable $afterChild = null,
    ): AgentInterface {
        $this->parallelToolCalls = $enabled;
        $this->beforeParallelToolChild = $beforeChild !== null
            ? $beforeChild(...)
            : null;
        $this->afterParallelToolChild = $afterChild !== null
            ? $afterChild(...)
            : null;

        return $this;
    }

    protected function state(): AgentState
    {
        return new AgentState();
    }

    /**
     * Ten minutes: above any single provider or tool call in ordinary use,
     * and the longest a thread stays refused after its process was killed
     * with no chance to record the failure. Override the hook, or call
     * setLeaseTimeout() (null disables), for slower nodes.
     */
    protected function leaseTimeout(): ?int
    {
        return 600;
    }

    /**
     * @throws ChatHistoryException
     */
    protected function chatHistory(): ChatHistoryInterface
    {
        // With no explicit threadId the history self-keys, and its key is
        // adopted as the run's identity by the lazy fallback.
        return new InMemoryChatHistory($this->threadId);
    }

    /**
     * A pre-bound history explicitly selects the conversation; an unbound
     * one receives the current thread identity. Swapping conversations clears
     * local run context while preserving their histories and durable runs.
     *
     * @throws AgentException
     */
    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        if ($this->executing) {
            throw new AgentException('Cannot replace chat history while the agent is executing.');
        }

        $threadId = $chatHistory->getThreadId();

        if ($threadId !== null && $this->threadId !== null && $threadId !== $this->threadId) {
            $this->threadId = $threadId;
            $this->workflowId = null;
            $this->runId = null;
            $this->state = null;
            $this->stagedSignalName = null;
            $this->stagedSignalPayload = [];
            $this->stagedInputs = null;
            $this->forceNewRun = false;
            $this->startEvent = null;
        }

        $this->attachChatHistory($chatHistory);

        return $this;
    }

    /**
     * Reconcile identity between the history and the agent. When both are
     * unresolved the history is stored as-is and adoptThreadId() binds it
     * the moment identity arrives.
     */
    protected function attachChatHistory(ChatHistoryInterface $chatHistory): void
    {
        $threadId = $chatHistory->getThreadId();

        if ($threadId !== null) {
            $this->adoptThreadId($threadId);
        } elseif ($this->threadId !== null) {
            $chatHistory->setThreadId($this->threadId);
        }

        $this->chatHistory = $chatHistory;
    }

    /**
     * Implicit identity adoption validates the selected conversation. Only
     * an explicit setChatHistory() call may select a different conversation.
     *
     * @throws AgentException
     */
    protected function adoptThreadId(string $threadId): void
    {
        if ($this->threadId !== null && $this->threadId !== $threadId) {
            throw new AgentException(
                "Conflicting thread identity: '{$threadId}' does not match the agent's '{$this->threadId}'."
            );
        }

        $this->threadId = $threadId;

        if (isset($this->chatHistory) && $this->chatHistory->getThreadId() === null) {
            $this->chatHistory->setThreadId($threadId);
        }
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        if (!isset($this->chatHistory)) {
            $this->attachChatHistory($this->chatHistory());
        }

        return $this->chatHistory;
    }

    /**
     * Clear chat history and abandon the pending execution for this conversation.
     */
    public function resetConversation(): self
    {
        $chatHistory = $this->getChatHistory();

        // The history is wiped below, so a pending approval cannot dangle:
        // the engine verb frees the thread without abandonRun()'s guard.
        parent::abandonRun();

        $chatHistory->flushAll();

        return $this;
    }

    /**
     * Suspended tool cycles already have an assistant tool call in history.
     * They must be settled before abandonment to avoid leaving an unanswered
     * call in the next inference's context.
     *
     * @throws AgentException
     */
    public function abandonRun(?string $expectedRunId = null): bool
    {
        $messages = $this->getChatHistory()->getMessages();
        $lastMessage = end($messages);
        if ($lastMessage instanceof ToolCallMessage) {
            throw new AgentException(
                'The conversation has an unanswered tool call: settle its approval or result '
                . 'with submitInputs() before abandoning the run.'
            );
        }

        return parent::abandonRun($expectedRunId);
    }

    /**
     * Rebuild the live tool registry after restoring request data. Middleware
     * reapply their contributions before execution; live state is never reset.
     */
    public function restoreState(WorkflowState $state): WorkflowState
    {
        if ($state instanceof AgentState && isset($state->request)) {
            $state->request->tools = $this->bootstrapTools();
        }

        return $state;
    }

    /**
     * @return Node[]
     */
    protected function nodes(): array
    {
        $this->toolsBootstrapCache = [];

        $chatHistory = $this->getChatHistory();

        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode(
                $chatHistory,
                $this->toolMaxRuns,
                $this->resolveToolErrorHandler(),
                $this->beforeParallelToolChild,
                $this->afterParallelToolChild,
            )
            : new ToolNode($chatHistory, $this->toolMaxRuns, $this->resolveToolErrorHandler());

        $nodes = [
            ...$this->entryNodes(),
            new ChatNode($this->getProvider(), $chatHistory),
            new StructuredOutputNode($this->getProvider(), $chatHistory),
            $toolNode,
            new AwaitToolResultsNode($chatHistory),
        ];

        return [...$nodes, ...$this->exitNodes()];
    }

    /**
     * @return Node[]
     */
    protected function exitNodes(): array
    {
        return [new AgentEndNode()];
    }

    /**
     * Hook method for child classes.
     *
     * @return Node[]
     */
    protected function entryNodes(): array
    {
        // Bootstrap first: it rewrites the instructions (toolkit guidelines),
        // so resolving them earlier would hand the node the stale message.
        $tools = $this->bootstrapTools();

        return [
            new AgentStartNode(
                $this->getInstructions(),
                $tools,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ignitionContext(): array
    {
        $threadId = $this->getThreadId();

        return $threadId === null ? [] : ['threadId' => $threadId];
    }

    /**
     * @param array<string, mixed> $context
     * @throws AgentException
     */
    protected function applyIgnitionContext(array $context): void
    {
        $threadId = $context['threadId'] ?? null;

        if (is_string($threadId)) {
            // A record contradicting an explicitly given identity is a
            // misidentified continuation — adoption throws.
            $this->adoptThreadId($threadId);
        }
    }

    /**
     * Thread-findability requires identity declared BEFORE the run starts —
     * identity discovered later (a pre-bound hook history materializing
     * during bootstrap) is adopted and validated, but arrives after the
     * ignition record and pointer are written.
     */
    public function getThreadId(): ?string
    {
        return $this->threadId;
    }

    /**
     * The Agent's business identity is the conversation: the threadId IS the
     * workflow ID, so a continuation holding only the thread finds the run.
     */
    public function workflowId(): ?string
    {
        return $this->getThreadId();
    }

    protected function startEvent(): AgentStartEvent
    {
        return new AgentStartEvent();
    }

    /**
     * @param Generator<int, object, mixed, AgentState> $generator
     * @return Generator<int, object, mixed, AgentState>
     */
    protected function forwardEvents(Generator $generator): Generator
    {
        $wasExecuting = $this->executing;
        $this->executing = true;

        try {
            return yield from parent::forwardEvents($generator);
        } finally {
            $this->executing = $wasExecuting;
        }
    }

    /**
     * @throws WorkflowException
     */
    protected function prepareNewTurn(): void
    {
        $this->assertNoStagedOperation();
        $this->setStartEvent($this->startEvent());
        $this->forceNewRun = true;
    }

    /**
     * A new turn starts a new run — to continue a suspended run use
     * {@see run()}. Runs eagerly to completion; the returned state
     * surfaces an approval pause via {@see WorkflowState::isInterrupted()}.
     *
     * @param Message|Message[] $messages
     * @throws AgentException
     * @throws Throwable
     * @throws WorkflowException
     */
    public function chat(Message|array $messages = []): AgentState
    {
        $this->prepareNewTurn();
        $this->getStartEvent()->messages = is_array($messages) ? $messages : [$messages];

        return $this->run();
    }

    /**
     * With an adapter and channel, stream eagerly and return the final AgentState.
     * Otherwise, return a lazy generator of native chunks or adapted protocol events;
     * {@see Generator::getReturn()} is the final {@see AgentState}.
     *
     * @param Message|Message[] $messages
     * @return Generator<int, object, mixed, AgentState>|AgentState
     * @throws AgentException
     * @throws Throwable
     * @throws WorkflowException
     */
    public function stream(Message|array $messages = []): Generator|AgentState
    {
        $this->prepareNewTurn();
        $this->getStartEvent()->options->stream = true;
        $this->getStartEvent()->messages = is_array($messages) ? $messages : [$messages];
        return $this->events();
    }

    /**
     * @param Message|Message[] $messages
     * @throws AgentException
     * @throws Throwable
     */
    public function structured(
        Message|array $messages = [],
        ?string $class = null,
        int $maxRetries = 1,
    ): mixed {
        $this->prepareNewTurn();
        $this->getStartEvent()->options->outputClass = $class ?? $this->getOutputClass();
        $this->getStartEvent()->options->maxRetries = $maxRetries;
        $this->getStartEvent()->messages = is_array($messages) ? $messages : [$messages];

        $finalState = $this->run();

        return $finalState->get('structured_output');
    }

    /**
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to set a structured output class.');
    }

    /**
     * The tool calls still awaiting a human decision on the current interruption.
     *
     * @return Action[]
     */
    public function pendingApprovals(): array
    {
        $request = $this->inspect()?->interrupt;

        if (!$request instanceof ApprovalRequest) {
            return [];
        }

        return array_values(array_filter(
            $request->getActions(),
            static fn (Action $action): bool => $action->isPending(),
        ));
    }

    /**
     * @throws InputTranslationException
     * @throws WorkflowException
     */
    public function submitApprovalDecisions(array $decisions): static
    {
        return $this->submitInputs($decisions, new ApprovalTranslator());
    }

    /**
     * @throws InputTranslationException
     * @throws WorkflowException
     */
    public function submitToolResults(array $results): static
    {
        return $this->submitInputs($results, new ToolResultsTranslator());
    }
}
