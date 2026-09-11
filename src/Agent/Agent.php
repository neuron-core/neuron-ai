<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Closure;
use Generator;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Agent\Memory\MemoryInterface;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\AwaitToolResultsNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\RecallMemoryNode;
use NeuronAI\Agent\Nodes\StartNode;
use NeuronAI\Agent\Nodes\StoreMemoryNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

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

    protected ?MemoryInterface $memory = null;

    protected bool $recallMemory = true;

    protected bool $rememberMemory = true;

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
     * Provide the default long-term memory implementation. Subclasses may
     * override this hook; null keeps the Agent memory-free.
     */
    protected function memory(): ?MemoryInterface
    {
        return null;
    }

    public function setMemory(MemoryInterface $memory): self
    {
        $this->memory = $memory;

        return $this;
    }

    /**
     * Configure how attached memory participates in each new run. The policy
     * is copied to the start event, so a suspended run keeps its original
     * choices when resumed while later runs may choose differently.
     */
    public function setMemoryUsage(bool $recall = true, bool $remember = true): self
    {
        $this->recallMemory = $recall;
        $this->rememberMemory = $remember;

        return $this;
    }

    final public function getMemory(): ?MemoryInterface
    {
        return $this->memory ??= $this->memory();
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
     * Permanently clear both long-term memory and chat history for this conversation.
     */
    public function resetConversation(): self
    {
        $chatHistory = $this->getChatHistory();
        $memory = $this->getMemory();

        // The history is wiped below, so a pending approval cannot dangle:
        // the engine verb frees the thread without abandonRun()'s guard.
        parent::abandonRun();

        if ($memory instanceof MemoryInterface) {
            $threadId = $chatHistory->getThreadId() ?? throw new ChatHistoryException(
                'Cannot reset memory for an unbound chat history.'
            );
            $memory->forget($threadId);
        }

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
                'The conversation has an unanswered tool call: settle the pending '
                . 'toolApprovalDecisions() or toolResults() before abandoning the run.'
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
        $memory = $this->getMemory();
        $memoryAvailable = $memory instanceof MemoryInterface;

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
            new ChatNode($this->getProvider(), $chatHistory, $memoryAvailable),
            new StructuredOutputNode($this->getProvider(), $chatHistory, $memoryAvailable),
            $toolNode,
            new AwaitToolResultsNode($chatHistory),
        ];

        if ($memory instanceof MemoryInterface) {
            $nodes[] = new RecallMemoryNode($memory, $chatHistory);
            $nodes[] = new StoreMemoryNode($memory, $chatHistory);
        }

        return $nodes;
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
            new StartNode(
                $this->getInstructions(),
                $tools,
                $this->getMemory() instanceof MemoryInterface,
            ),
        ];
    }

    public function makeIgnition(string $runId): Ignition
    {
        $this->getStartEvent()->options->recallMemory = $this->recallMemory;
        $this->getStartEvent()->options->rememberMemory = $this->rememberMemory;

        return parent::makeIgnition($runId);
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
        return new AgentStartEvent(options: new AgentRunOptions(
            recallMemory: $this->recallMemory,
            rememberMemory: $this->rememberMemory,
        ));
    }

    /**
     * @param Generator<int, object|string, mixed, AgentState> $generator
     * @return Generator<int, object|string, mixed, AgentState>
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
        $this->setStartEvent($this->startEvent());
        $this->getStartEvent()->messages = is_array($messages) ? $messages : [$messages];

        return $this->run();
    }

    /**
     * The pull-stream verb: yields Neuron chunks, and
     * {@see Generator::getReturn()} is the final {@see AgentState}. A stream
     * adapter configured on the Workflow transforms the yielded output and,
     * when a channel is attached, the same lines are delivered there.
     *
     * @param Message|Message[] $messages
     * @return Generator<int, object|string, mixed, AgentState>
     * @throws AgentException
     * @throws Throwable
     * @throws WorkflowException
     */
    public function stream(Message|array $messages = []): Generator
    {
        $this->setStartEvent($this->startEvent());
        $this->getStartEvent()->options->stream = true;
        $this->getStartEvent()->messages = is_array($messages) ? $messages : [$messages];
        return yield from $this->events();
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
        $this->setStartEvent($this->startEvent());
        $this->getStartEvent()->options->outputClass = $class ?? $this->getOutputClass();
        $this->getStartEvent()->options->maxRetries = $maxRetries;
        $this->getStartEvent()->messages = is_array($messages) ? $messages : [$messages];

        $finalState = $this->run();

        return $finalState->get('structured_output');
    }

    /**
     * @param array<array-key, mixed> $decisions
     * @throws WorkflowException
     */
    public function toolApprovalDecisions(array $decisions): static
    {
        return $this->signal(ApprovalRequest::EVENT_NAME, $decisions);
    }

    /**
     * @param array<array-key, array{result?: mixed, error?: string}> $results
     * @throws WorkflowException
     */
    public function toolResults(array $results): static
    {
        return $this->signal(ToolResultsRequest::EVENT_NAME, $results);
    }

    /**
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to set a structured output class.');
    }

}
