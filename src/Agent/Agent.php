<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Generator;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\RecallMemoryEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Memory\MemoryInterface;
use NeuronAI\Agent\Nodes\ChatNode;
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
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function array_filter;
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
     * ID. Assigned exactly once through adoptThreadId() and NEVER generated —
     * identity is always a developer statement. Null means the run is not
     * findable by its thread.
     */
    protected ?string $threadId = null;

    protected bool $parallelToolCalls = false;

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

    public function parallelToolCalls(bool $enabled): AgentInterface
    {
        $this->parallelToolCalls = $enabled;
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
     * A pre-bound history declares thread identity by adoption (conflicts
     * throw); an unbound one receives the agent's resolved identity before
     * first use — identity never needs to appear in wiring code.
     */
    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
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

        if (isset($this->startEvent) && $this->startEvent instanceof AgentStartEvent) {
            $this->startEvent->setMemoryUsage($recall, $remember);
        }

        return $this;
    }

    final public function getMemory(): ?MemoryInterface
    {
        return $this->memory ??= $this->memory();
    }

    /**
     * The single assignment door for thread identity. A conflicting identity
     * is always a wiring bug and throws — two disagreeing claims about the
     * same conversation have no honest silent resolution.
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
     * A turn paused on an approval has already written the assistant's tool
     * call to history; abandoning the run would leave it unanswered, and
     * every provider rejects the next turn. Settle it with
     * toolApprovalDecisions() instead. Dead turns abandon freely: their
     * inbound message was never committed, so nothing dangles.
     *
     * @throws AgentException
     */
    public function abandonRun(?string $expectedRunId = null): bool
    {
        $messages = $this->getChatHistory()->getMessages();
        $lastMessage = end($messages);
        $pending = $lastMessage instanceof ToolCallMessage
            ? array_filter(
                $lastMessage->getToolCalls(),
                static fn (ToolCall $call): bool => $call->getApprovalState() === ApprovalState::Pending,
            )
            : [];

        if ($pending !== []) {
            throw new AgentException(
                'The conversation is waiting on a tool approval: settle it with '
                . 'toolApprovalDecisions() before abandoning the run.'
            );
        }

        return parent::abandonRun($expectedRunId);
    }

    /**
     * Persisted events drop the live tool registry (tools hold connections,
     * clients, closures — see AIInferenceEvent::__serialize), so a recalled
     * event comes back with an empty tool list. Re-seed the base registry
     * here; middleware re-supply their own additions in before(). Called only
     * on recalled events — a live event's effective set is never touched.
     */
    public function restoreEvent(Event $event): Event
    {
        $inference = match (true) {
            $event instanceof AIInferenceEvent => $event,
            $event instanceof RecallMemoryEvent => $event->inferenceEvent,
            $event instanceof ToolCallEvent => $event->inferenceEvent,
            default => null,
        };

        if ($inference instanceof AIInferenceEvent) {
            $inference->tools = $this->bootstrapTools();
        }

        return $event;
    }

    protected function compose(): void
    {
        if ($this->eventNodeMap !== []) {
            return;
        }

        $chatHistory = $this->getChatHistory();
        $memory = $this->getMemory();
        $memoryAvailable = $memory instanceof MemoryInterface;

        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode($chatHistory, $this->toolMaxRuns, $this->resolveToolErrorHandler())
            : new ToolNode($chatHistory, $this->toolMaxRuns, $this->resolveToolErrorHandler());

        $nodes = [
            ...$this->entryNodes(),
            new ChatNode($this->getProvider(), $chatHistory, $memoryAvailable),
            new StructuredOutputNode($this->getProvider(), $chatHistory, $memoryAvailable),
            $toolNode,
        ];

        if ($memory instanceof MemoryInterface) {
            $nodes[] = new RecallMemoryNode($memory, $chatHistory);
            $nodes[] = new StoreMemoryNode($memory, $chatHistory);
        }

        $this->addNodes($nodes);
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

    /**
     * @throws WorkflowException
     */
    public function bootstrap(): void
    {
        $this->compose();
        parent::bootstrap();
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
        return (new AgentStartEvent())->setMemoryUsage(
            recall: $this->recallMemory,
            remember: $this->rememberMemory,
        );
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
        $this->getStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

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
        $this->getStartEvent()->setStream()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );
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
        $this->getStartEvent()
            ->setStructuredOutput($class ?? $this->getOutputClass(), $maxRetries)
            ->setMessages(
                ...(is_array($messages) ? $messages : [$messages])
            );

        $finalState = $this->run();

        return $finalState->get('structured_output');
    }

    /**
     * @param array<array-key, mixed> $decisions
     * @throws WorkflowException
     */
    public function toolApprovalDecisions(array $decisions): static
    {
        return $this->signal('approval', $decisions);
    }

    /**
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to set a structured output class.');
    }

}
