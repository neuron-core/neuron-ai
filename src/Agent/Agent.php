<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

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
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function is_array;
use function is_string;

/**
 * @method static static make(?string $runId = null, ?WorkflowState $state = null, ?string $threadId = null)
 * @method AgentState run() Run to completion; return type narrowed covariantly from {@see WorkflowState}
 * @method AgentStartEvent getStartEvent()
 * @method AgentState getState()
 */
class Agent extends Workflow implements AgentInterface
{
    use HandleProvider;
    use HandleTools;
    use HandleInstructions;

    protected ChatHistoryInterface $chatHistory;

    /**
     * The agent's thread identity — the conversation this run belongs to, and
     * the run's correlation key. Nullable until resolved, assigned exactly
     * once through adoptThreadId() (mirroring the engine's runId phase), and
     * NEVER generated: identity is always a developer statement. Sources, in
     * precedence order: the explicit make(threadId:) parameter, adoption from
     * a pre-bound chat history, the ignition record on a run-first resume.
     * Null means the run is not thread-addressable.
     */
    protected ?string $threadId = null;

    protected bool $parallelToolCalls = false;

    public function __construct(
        ?string $runId = null,
        ?WorkflowState $state = null,
        ?string $threadId = null,
    ) {
        parent::__construct($runId, $state);

        if ($threadId !== null) {
            $this->adoptThreadId($threadId);
        }
    }

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
        // Identity-aware default: an explicit threadId keys the in-memory
        // store; with none, the history self-keys (its own storage default,
        // adopted as the run's identity by the lazy fallback).
        return new InMemoryChatHistory($this->threadId);
    }

    /**
     * A pre-bound history (constructed with its thread) declares thread
     * identity by adoption — its key becomes the agent's, conflicts throw.
     * An unbound history (constructed without a thread, e.g.
     * `new SQLChatHistory($pdo)`) is the recommended wiring: the agent binds
     * the resolved identity into it before first use — from make(threadId:),
     * or from the ignition record on a run-first resume. Identity never
     * needs to appear in wiring code.
     */
    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        $this->attachChatHistory($chatHistory);
        return $this;
    }

    /**
     * Attach a history and reconcile identity between it and the agent:
     * a bound history declares identity (adoption, conflicts throw); an
     * unbound one receives the agent's resolved identity; when both are
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
     * The single assignment door for thread identity (mirrors the engine's
     * adoptRunId): called by the constructor (explicit parameter),
     * attachChatHistory (adoption from a pre-bound history), and
     * applyIgnitionContext (run-first resume). Assigning a conflicting
     * identity is always a wiring bug and throws — two disagreeing claims
     * about the same conversation have no honest silent resolution. The
     * tail binds an already-attached, still-unbound history.
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
     * The agent's transient capability is its live tool registry: persisted
     * events drop it (AIInferenceEvent::__serialize — tools hold connections,
     * clients, closures), so a recalled inference or tool-call event comes back
     * with an empty tool list. Re-seed the base registry here; middleware
     * re-supply their own additions in before() (each contributor restores what
     * it contributes). The engine calls this only on events recalled from
     * persistence — a live event's effective set (middleware additions and
     * removals included) is never touched.
     */
    public function restoreEvent(Event $event): Event
    {
        $inference = match (true) {
            $event instanceof AIInferenceEvent => $event,
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

        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode($this->getChatHistory(), $this->toolMaxRuns, $this->resolveToolErrorHandler())
            : new ToolNode($this->getChatHistory(), $this->toolMaxRuns, $this->resolveToolErrorHandler());

        $this->addNodes([
            ...$this->entryNodes(),
            new ChatNode($this->getProvider(), $this->getChatHistory()),
            new StructuredOutputNode($this->getProvider(), $this->getChatHistory()),
            $toolNode,
        ]);
    }

    /**
     * Hook method for child classes.
     *
     * @return Node[]
     */
    protected function entryNodes(): array
    {
        // Bootstrap first: it rewrites the instructions (toolkit guidelines),
        // so resolving them before it would hand the node the stale message.
        $tools = $this->bootstrapTools();

        return [
            new StartNode($this->getInstructions(), $tools),
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
     * The thread identity — read from the chat history (the single source of
     * truth)
     *
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
            // Adoption validates as it assigns: a record whose identity
            // contradicts an explicitly given one is a mis-addressed
            // continuation and throws.
            $this->adoptThreadId($threadId);
        }
    }

    /**
     * The agent's thread identity, or null when none was declared. A pure
     * read of the slot: addressability requires identity declared BEFORE
     * the run starts (make(threadId:), or a pre-bound history passed to
     * setChatHistory()) — identity discovered later (a pre-bound hook
     * history materializing during bootstrap) is adopted and validated, but
     * arrives after the ignition record and pointer are written.
     */
    public function getThreadId(): ?string
    {
        return $this->threadId;
    }

    /**
     * The Agent's business identity is the conversation: the run is
     * addressable by its threadId, so the engine binds threadId → runId at
     * ignition and a continuation holding only the thread finds the run.
     * Null means no thread identity is resolvable here — a run-first resume
     * before ignition adoption (the explicit runId addresses the run), or a
     * deliberately anonymous run.
     */
    public function correlationKey(): ?string
    {
        return $this->getThreadId();
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
        $this->getStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        return $this->run();
    }

    /**
     * The pull-stream verb: a generator yielding Neuron chunks (TextChunk,
     * ToolCallChunk, …) whose {@see Generator::getReturn()} is the final
     * {@see AgentState}. Pass a {@see StreamAdapterInterface} to yield
     * protocol-adapted lines instead (Vercel AI SDK, AG-UI, SSE) — the start/
     * transform/end sequences are emitted lazily around the raw items. To push
     * adapted output to a channel instead, attach the adapter via
     * {@see setStreamAdapter()} — the workflow then runs each chunk through it
     * and delivers the resulting lines to the channel's sendLine() port.
     *
     * @param Message|Message[] $messages
     * @return Generator<int, object|string, mixed, AgentState>
     * @throws AgentException
     * @throws Throwable
     * @throws WorkflowException
     */
    public function stream(Message|array $messages = [], ?StreamAdapterInterface $adapter = null): Generator
    {
        $this->getStartEvent()->setStream()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $generator = $this->events();

        if ($adapter instanceof StreamAdapterInterface) {
            foreach ($adapter->start() as $output) {
                yield $output;
            }
        }

        foreach ($generator as $event) {
            if ($adapter instanceof StreamAdapterInterface) {
                foreach ($adapter->transform($event) as $output) {
                    yield $output;
                }
            } else {
                yield $event;
            }
        }

        if ($adapter instanceof StreamAdapterInterface) {
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
        $this->getStartEvent()
            ->setStructuredOutput($class ?? $this->getOutputClass(), $maxRetries)
            ->setMessages(
                ...(is_array($messages) ? $messages : [$messages])
            );

        /** @var AgentState $finalState */
        $finalState = $this->run();

        return $finalState->get('structured_output');
    }

    /**
     * The single continuation verb — delivers the inbound payload to a
     * suspended run. It carries no identity logic of its own: the engine's
     * identity-resolution phase addresses the run, either from an explicit
     * runId (run-first) or from the thread's correlation pointer
     * (thread-first, {@see correlationKey()}).
     *
     * @param array<string, mixed> $payload
     * @throws Throwable
     * @throws WorkflowException
     */
    public function resume(array $payload = [], bool $timedOut = false): AgentState
    {
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

}
