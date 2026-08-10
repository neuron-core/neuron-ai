<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Closure;
use Generator;
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
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
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
     * Pending resolver forms of the collaborator setters (closures receiving
     * string $threadId). Non-null means "wired but not yet materialized" —
     * each is nulled the moment it resolves, which is what makes
     * materializeResolvers() idempotent.
     *
     * The history resolver is resume-only: it can only fire when a threadId is
     * recovered from the ignition record (applyIgnitionContext). On a fresh
     * run the developer passes a concrete history, whose identity is the
     * single source of truth (read back via getChatHistory()->getThreadId()).
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
     * `string $threadId`. The resolver is resume-only (fired from the ignition
     * record); on a fresh run pass a concrete history, whose identity is the
     * single source of truth. A concrete instance clears a pending resolver
     * and materializes any pending channel resolver against its identity.
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
        $this->materializeResolvers();
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

    /**
     * Resolve any pending collaborator resolvers. Idempotent (a resolver is
     * nulled the moment it materializes).
     *
     * Two modes:
     *  - Resume ($threadId provided by applyIgnitionContext): the recovered
     *    identity feeds both pending resolvers (history first, then channel).
     *    This is the only path that can materialize a history resolver.
     *  - Ignition ($threadId null): no identity source, so a pending history
     *    resolver stays pending (getChatHistory() fails loud). A pending
     *    channel resolver is fed from the concrete history's getThreadId().
     *
     * @throws AgentException when a resolver returns the wrong type.
     */
    protected function materializeResolvers(?string $threadId = null): void
    {
        if ($threadId !== null) {
            if ($this->chatHistoryResolver instanceof Closure) {
                $chatHistory = ($this->chatHistoryResolver)($threadId);

                if (!$chatHistory instanceof ChatHistoryInterface) {
                    throw new AgentException('The chat history resolver must return a ' . ChatHistoryInterface::class . '.');
                }

                $this->chatHistoryResolver = null;
                $this->chatHistory = $chatHistory;
            }

            if ($this->channelResolver instanceof Closure) {
                $channel = ($this->channelResolver)($threadId);

                if (!$channel instanceof ChannelInterface) {
                    throw new AgentException('The channel resolver must return a ' . ChannelInterface::class . '.');
                }

                $this->channelResolver = null;
                parent::setChannel($channel);
            }

            return;
        }

        // Ignition path: no threadId source. A pending history resolver stays
        // pending (getChatHistory() fails loud when read). A pending channel
        // resolver is fed from the concrete history's getThreadId() — but only
        // when the history is already concrete. When both resolvers are pending
        // (a resume factory before applyIgnitionContext), defer both to the resume.
        if ($this->channelResolver instanceof Closure && $this->chatHistoryResolver === null) {
            $channel = ($this->channelResolver)($this->getChatHistory()->getThreadId());

            if (!$channel instanceof ChannelInterface) {
                throw new AgentException('The channel resolver must return a ' . ChannelInterface::class . '.');
            }

            $this->channelResolver = null;
            parent::setChannel($channel);
        }
    }

    /**
     * Fail loudly at the real hazard: a history resolver pending on a fresh run
     * (it is resume-only — fired from the ignition record). Falling back to the
     * default InMemoryChatHistory would silently read and write the wrong
     * (empty) thread.
     */
    public function getChatHistory(): ChatHistoryInterface
    {
        if ($this->chatHistoryResolver instanceof Closure) {
            throw new AgentException(
                'A chat history resolver can only be resolved on a resume (the threadId is recovered '
                . 'from the ignition record): on a fresh run pass a concrete chat history instance.'
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
     * method having been called. Both resolvers are guaranteed resolved before
     * this point (history at set-time or via ignition adoption; channel from
     * the history identity at set-time or the ignition threadId on a resume).
     *
     * @throws WorkflowException
     */
    public function bootstrap(): void
    {
        $this->compose();
        parent::bootstrap();
    }

    /**
     * The thread identity — read from the chat history (the single source of
     * truth) — is the Agent's run context (the engine-opaque envelope slot):
     * recorded on the first segment, applied by a blank process on a resume,
     * where it materializes any wired resolvers before bootstrap() constructs
     * nodes with getChatHistory().
     *
     * @return array<string, mixed>
     * @throws AgentException
     */
    protected function ignitionContext(): array
    {
        return ['threadId' => $this->getChatHistory()->getThreadId()];
    }

    /**
     * @param array<string, mixed> $context
     * @throws AgentException
     */
    protected function applyIgnitionContext(array $context): void
    {
        $threadId = $context['threadId'] ?? null;

        if (is_string($threadId)) {
            $this->materializeResolvers($threadId);
        }
    }

    protected function startEvent(): AgentStartEvent
    {
        return new AgentStartEvent();
    }

    /**
     * Pure ignition: a new turn starts a new run. To continue a suspended run
     * use {@see resume()}.
     *
     * Chat mode is buffered, so this runs eagerly to completion and returns the
     * final state — {@see AgentState::getMessage()} reads the assistant message
     * off the stored provider response, and {@see WorkflowState::isInterrupted()}
     * surfaces an approval pause.
     *
     * @param Message|Message[] $messages
     * @throws AgentException
     * @throws Throwable
     * @throws WorkflowException
     */
    public function chat(Message|array $messages = []): AgentState
    {
        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        return $this->run();
    }

    /**
     * The pull-stream verb: a generator yielding Neuron chunks (TextChunk,
     * ToolCallChunk, …) whose {@see Generator::getReturn()} is the final
     * {@see AgentState}. Pass a {@see StreamAdapterInterface} to yield
     * protocol-adapted lines instead (Vercel AI SDK, AG-UI, SSE) — the start/
     * transform/end sequences are emitted lazily around the raw items, matching
     * the old pull path. Push delivery to a live sink is still available by
     * wiring a {@see \NeuronAI\Workflow\Channel\StreamAdapterChannel} on the channel.
     *
     * @param Message|Message[] $messages
     * @return Generator<int, object|string, mixed, AgentState>
     * @throws AgentException
     * @throws Throwable
     * @throws WorkflowException
     */
    public function stream(Message|array $messages = [], ?StreamAdapterInterface $adapter = null): Generator
    {
        $this->resolveStartEvent()->setStream()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $generator = $this->events();

        if ($adapter !== null) {
            foreach ($adapter->start() as $output) {
                yield $output;
            }
        }

        foreach ($generator as $event) {
            if ($adapter !== null) {
                foreach ($adapter->transform($event) as $output) {
                    yield $output;
                }
            } else {
                yield $event;
            }
        }

        if ($adapter !== null) {
            foreach ($adapter->end() as $output) {
                yield $output;
            }
        }

        /** @var AgentState $state */
        $state = $generator->getReturn();
        return $state;
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
        $this->resolveStartEvent()
            ->setStructuredOutput($class ?? $this->getOutputClass(), $maxRetries)
            ->setMessages(
                ...(is_array($messages) ? $messages : [$messages])
            );

        /** @var AgentState $finalState */
        $finalState = $this->run();

        return $finalState->get('structured_output');
    }

    /**
     * Run to completion and return the final state, narrowed to the Agent's
     * own state type. Inherits the workflow traversal; only the return type is
     * specialized (covariant — {@see AgentState} extends {@see WorkflowState}).
     *
     * @throws WorkflowException
     * @throws Throwable
     */
    public function run(): AgentState
    {
        /** @var AgentState $state */
        $state = parent::run();
        return $state;
    }

    /**
     * The single continuation verb — delivers the inbound payload to a
     * suspended run. With the runId adoption every continuation path shares
     * (ADR 0005): a blank factory reattaches to the run whose id is stamped on
     * the chat-history tail.
     *
     * @param array<string, mixed> $payload
     * @throws Throwable
     * @throws WorkflowException
     */
    public function resume(array $payload = [], bool $timedOut = false): AgentState
    {
        $this->adoptRunIdFromHistory();

        /** @var AgentState $state */
        $state = parent::resume($payload, $timedOut);
        return $state;
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
     * A continuation targets the suspended run recorded on the thread: when the
     * tail of chat history is a ToolCallMessage stamped with a runId (ADR 0005),
     * that id identifies the run to reattach to — chat history is the system of
     * record, so nothing else needs to be stored or passed. The stamp wins even
     * over a runId passed to make(); with no stamp on the tail the agent keeps
     * its current runId (e.g. an explicit runId for a non-approval suspension).
     *
     * Skipped while a chat-history resolver is pending: without a thread
     * identity there is no tail to read — the explicit runId governs, and the
     * threadId arrives via the adopted ignition context during the resume.
     */
    protected function adoptRunIdFromHistory(): void
    {
        if ($this->chatHistoryResolver instanceof Closure) {
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
