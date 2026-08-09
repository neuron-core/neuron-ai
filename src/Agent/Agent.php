<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Closure;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\StartNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Channel\ChannelInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function end;
use function is_array;
use function is_string;

/**
 * @method static static make(?string $runId = null, ?WorkflowState $state = null)
 * @method AgentStartEvent resolveStartEvent()
 * @method AgentState resolveState()
 */
class Agent extends Workflow implements AgentInterface
{
    use ResolveProvider;
    use HandleTools;
    use HandleInstructions;

    protected ChatHistoryInterface $chatHistory;

    /**
     * One identity, three appearances: frontend threadId = chat history
     * thread id = channel name. Recorded into the ignition context on the
     * first segment; on wakes it arrives via applyIgnitionContext(), so the
     * developer never sets it on a wake path.
     */
    protected ?string $threadId = null;

    /**
     * Pending resolver forms of the collaborator setters (closures receiving
     * string $threadId). Non-null means "wired but not yet materialized" —
     * each is nulled the moment it resolves, which is what makes
     * materializeResolvers() idempotent.
     */
    protected ?Closure $chatHistoryResolver = null;

    protected ?Closure $channelResolver = null;

    protected bool $parallelToolCalls = false;

    /**
     * Determines whether tools should be executed in parallel.
     */
    public function parallelToolCalls(bool $enabled): AgentInterface
    {
        $this->parallelToolCalls = $enabled;
        return $this;
    }

    protected function state(): AgentState
    {
        return new AgentState();
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new InMemoryChatHistory();
    }

    /**
     * Accepts a concrete history or its resolver form — a closure receiving
     * `string $threadId` — for factories that wire collaborators before the
     * thread identity is known. A concrete instance clears a pending resolver
     * (inline opt-out).
     */
    public function setChatHistory(ChatHistoryInterface|Closure $chatHistory): self
    {
        if ($chatHistory instanceof Closure) {
            $this->chatHistoryResolver = $chatHistory;
            $this->materializeResolvers();
            return $this;
        }

        $this->chatHistoryResolver = null;
        $this->chatHistory = $chatHistory;
        return $this;
    }

    /**
     * Accepts a concrete channel or its resolver form (closure receiving
     * `string $threadId`); a concrete instance clears a pending resolver.
     */
    public function setChannel(ChannelInterface|Closure $channel): static
    {
        if ($channel instanceof Closure) {
            $this->channelResolver = $channel;
            $this->materializeResolvers();
            return $this;
        }

        $this->channelResolver = null;
        return parent::setChannel($channel);
    }

    public function setThreadId(string $threadId): self
    {
        $this->threadId = $threadId;
        $this->materializeResolvers();
        return $this;
    }

    public function getThreadId(): ?string
    {
        return $this->threadId;
    }

    /**
     * Resolve any pending collaborator resolvers with the thread identity.
     * Order-independent (called from setThreadId() and both setters) and
     * idempotent (a resolver is nulled the moment it materializes).
     *
     * @throws AgentException when a resolver returns the wrong type.
     */
    protected function materializeResolvers(): void
    {
        if ($this->threadId === null) {
            return;
        }

        if ($this->chatHistoryResolver instanceof Closure) {
            $chatHistory = ($this->chatHistoryResolver)($this->threadId);

            if (!$chatHistory instanceof ChatHistoryInterface) {
                throw new AgentException('The chat history resolver must return a ' . ChatHistoryInterface::class . '.');
            }

            $this->chatHistoryResolver = null;
            $this->chatHistory = $chatHistory;
        }

        if ($this->channelResolver instanceof Closure) {
            $channel = ($this->channelResolver)($this->threadId);

            if (!$channel instanceof ChannelInterface) {
                throw new AgentException('The channel resolver must return a ' . ChannelInterface::class . '.');
            }

            $this->channelResolver = null;
            parent::setChannel($channel);
        }
    }

    /**
     * Fail loudly at the real hazard: with a resolver wired and no threadId,
     * falling back to the default InMemoryChatHistory would silently read and
     * write the wrong (empty) thread.
     */
    public function getChatHistory(): ChatHistoryInterface
    {
        if ($this->chatHistoryResolver instanceof Closure) {
            throw new AgentException(
                'A chat history resolver is wired but no threadId is set: call setThreadId() '
                . 'before running the agent, or pass a concrete chat history instance.'
            );
        }

        return $this->chatHistory ??= $this->chatHistory();
    }

    /**
     * Steps are per-execution-cycle: a state restored from a replayed snapshot
     * starts a fresh transcript. Serializing backends already strip it
     * (AgentState::__serialize); this covers InMemoryPersistence, which stores
     * live object references.
     */
    public function setState(WorkflowState $state): WorkflowInterface
    {
        if ($state instanceof AgentState && $state !== $this->state && $state->getSteps() !== []) {
            $state = clone $state;
            $state->resetSteps();
        }

        return parent::setState($state);
    }

    /**
     * The agent's transient capability is its live tool registry: persisted
     * events drop it (AIInferenceEvent::__serialize — tools hold connections,
     * clients, closures), so a recalled inference or tool-call event comes back
     * with an empty tool list. Re-seed the base registry here; middleware
     * re-supply their own additions in before() (each contributor restores what
     * it contributes). Idempotent: a live tool-calling event never has an empty
     * list, so this only ever touches stripped events.
     */
    public function restoreEventNode(Event $event): Event
    {
        $inference = match (true) {
            $event instanceof AIInferenceEvent => $event,
            $event instanceof ToolCallEvent => $event->inferenceEvent,
            default => null,
        };

        if ($inference instanceof AIInferenceEvent && $inference->tools === []) {
            $inference->tools = $this->bootstrapTools();
        }

        return $event;
    }

    /**
     * Prepare the agent workflow with the static node set. The graph is a pure
     * function of the agent definition: the entry chain and both inference
     * routes are always registered, and each event's exact class selects the
     * path at traversal time.
     */
    protected function compose(): void
    {
        if ($this->eventNodeMap !== []) {
            return;
        }

        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode($this->getChatHistory(), $this->toolMaxRuns, $this->resolveToolErrorHandler())
            : new ToolNode($this->getChatHistory(), $this->toolMaxRuns, $this->resolveToolErrorHandler());

        $this->addNodes([
            ...$this->entryNodes(),
            new ChatNode($this->resolveProvider(), $this->getChatHistory()),
            new StructuredOutputNode($this->resolveProvider(), $this->getChatHistory()),
            $toolNode,
        ]);
    }

    /**
     * The nodes between the bare start event and the inference nodes, ending
     * in the node that births the inference event: StartNode here, RAG's
     * retrieval chain ending in InstructionsNode there.
     *
     * @return Node[]
     */
    protected function entryNodes(): array
    {
        return [
            new StartNode($this->resolveInstructions(), $this->bootstrapTools()),
        ];
    }

    /**
     * Composition happens here — the lazy step every execution path passes
     * through — so bare run()/resume() work on an Agent without any sugar
     * method having been called. Backstops the channel resolver: the channel
     * is only touched lazily behind null-safe guards during delivery, so a
     * pending resolver would otherwise silently mean "no channel".
     */
    protected function bootstrap(): static
    {
        if ($this->channelResolver instanceof Closure) {
            throw new AgentException(
                'A channel resolver is wired but no threadId is set: call setThreadId() '
                . 'before running the agent, or pass a concrete channel instance.'
            );
        }

        $this->compose();
        return parent::bootstrap();
    }

    /**
     * The thread identity is the Agent's run context (the engine-opaque
     * envelope slot): recorded on the first segment, applied by a blank
     * process on a wake — where setThreadId() also materializes any wired
     * resolvers before bootstrap() constructs nodes with getChatHistory().
     *
     * @return array<string, mixed>
     */
    protected function ignitionContext(): array
    {
        return $this->threadId !== null ? ['threadId' => $this->threadId] : [];
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function applyIgnitionContext(array $context): void
    {
        $threadId = $context['threadId'] ?? null;

        if (is_string($threadId)) {
            $this->setThreadId($threadId);
        }
    }

    protected function startEvent(): AgentStartEvent
    {
        return new AgentStartEvent();
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start the run; a payload to resume a suspended agent.
     * @throws WorkflowException
     */
    public function chat(
        Message|array $messages = [],
        ?array $payload = null
    ): AgentHandler {
        $this->checkRunId($payload);

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        return new AgentHandler(
            $this->events($payload),
            $this->getChatHistory(),
        );
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start the run; a payload to resume a suspended agent.
     * @throws WorkflowException
     */
    public function stream(
        Message|array $messages = [],
        ?array $payload = null,
    ): AgentHandler {
        $this->checkRunId($payload);

        $this->resolveStartEvent()->setStream()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        return new AgentHandler(
            $this->events($payload),
            $this->getChatHistory(),
        );
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start the run; a payload to resume a suspended agent.
     * @throws AgentException
     * @throws Throwable
     */
    public function structured(
        Message|array $messages = [],
        ?string $class = null,
        int $maxRetries = 1,
        ?array $payload = null,
    ): mixed {
        $this->checkRunId($payload);

        $this->resolveStartEvent()
            ->setStructuredOutput($class ?? $this->getOutputClass(), $maxRetries)
            ->setMessages(
                ...(is_array($messages) ? $messages : [$messages])
            );

        /** @var AgentState $finalState */
        $finalState = $payload === null ? $this->run() : $this->resume($payload);

        return $finalState->get('structured_output');
    }

    /**
     * Get the class representing the structured output.
     *
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to set a structured output class.');
    }

    /**
     * A resume payload targets the suspended run recorded on the thread: when the
     * tail of chat history is a ToolCallMessage stamped with a runId (ADR 0005),
     * that id identifies the run to reattach to — chat history is the system of
     * record, so nothing else needs to be stored or passed. With no stamp on the
     * tail the agent keeps its current runId (e.g. a runId passed to make() for a
     * non-approval suspension).
     *
     * @param array<string, mixed>|null $payload Null starts a fresh turn; a non-null
     *                                           payload resumes a suspended workflow.
     */
    protected function checkRunId(?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        $messages = $this->getChatHistory()->getMessages();
        $last = end($messages);
        $runId = $last instanceof ToolCallMessage ? $last->getRunId() : null;

        if ($runId !== null) {
            $this->runId = $runId;
        }
    }

}
