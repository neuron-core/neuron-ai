<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Generator;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Memory\MemoryAwareChatHistory;
use NeuronAI\Agent\Memory\MemoryInterface;
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
 * @method static static make(?string $workflowId = null, ?WorkflowState $state = null, ?string $threadId = null)
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
     * The history instance shared by the composed nodes. When memory is
     * configured this is a decorator around the developer-supplied history.
     */
    protected ?ChatHistoryInterface $effectiveChatHistory = null;

    protected ?MemoryInterface $memory = null;

    protected bool $memoryResolved = false;

    /**
     * The conversation this run belongs to, and the run's declared workflow
     * ID. Assigned exactly once through adoptThreadId() and NEVER generated —
     * identity is always a developer statement. Null means the run is not
     * findable by its thread.
     */
    protected ?string $threadId = null;

    protected bool $parallelToolCalls = false;

    public function __construct(
        ?string $workflowId = null,
        ?WorkflowState $state = null,
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
        $this->effectiveChatHistory = null;
    }

    /**
     * Provide the default long-term memory implementation. Subclasses may
     * override this hook; null keeps the Agent memory-free.
     */
    protected function memory(): ?MemoryInterface
    {
        return null;
    }

    /**
     * Configure long-term memory before the Agent graph is composed.
     */
    public function setMemory(MemoryInterface $memory): self
    {
        if ($this->eventNodeMap !== []) {
            throw new AgentException('Memory must be configured before the Agent starts executing.');
        }

        $this->memory = $memory;
        $this->memoryResolved = true;
        $this->effectiveChatHistory = null;

        return $this;
    }

    public function getMemory(): ?MemoryInterface
    {
        if (!$this->memoryResolved) {
            $this->memory = $this->memory();
            $this->memoryResolved = true;
        }

        return $this->memory;
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

        if ($this->effectiveChatHistory === null) {
            $memory = $this->getMemory();
            $this->effectiveChatHistory = $memory instanceof MemoryInterface
                ? new MemoryAwareChatHistory($this->chatHistory, $memory)
                : $this->chatHistory;
        }

        return $this->effectiveChatHistory;
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
        // so resolving them earlier would hand the node the stale message.
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
     * A new turn starts a new run — to continue a suspended run use
     * {@see resume()}. Runs eagerly to completion; the returned state
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
     * {@see Generator::getReturn()} is the final {@see AgentState}. Pass a
     * {@see StreamAdapterInterface} to yield protocol-adapted lines instead
     * (Vercel AI SDK, AG-UI, SSE); for push delivery to a channel, attach the
     * adapter via {@see setStreamAdapter()} instead.
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
     * The single continuation verb. It carries no identity logic of its own:
     * the engine identifies the run from the threadId (the Agent's declared
     * workflow ID) or an explicit workflow ID. A null payload revives without
     * delivering anything; an empty array delivers an empty decision set.
     *
     * @param array<string, mixed>|null $payload
     * @throws Throwable
     * @throws WorkflowException
     */
    public function resume(?array $payload = null, bool $timedOut = false): AgentState
    {
        /** @var AgentState $state */
        $state = parent::resume($payload, $timedOut);
        return $state;
    }

    /**
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to set a structured output class.');
    }

}
