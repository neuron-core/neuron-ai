<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Closure;
use Generator;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\ChannelError;
use NeuronAI\Observability\ListenerRegistry;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use Throwable;
use function array_merge;
use function is_array;

/**
 * @template TState of WorkflowState = WorkflowState
 * @implements WorkflowInterface<TState>
 */
class Workflow implements WorkflowInterface, WorkflowRuntimeInterface
{
    use HandleMiddleware;
    /** @use ResolveState<TState> */
    use ResolveState;
    use HandleComponents;
    use HandleDispatcher;

    /**
     * @var NodeInterface[]
     */
    protected array $nodes = [];

    /**
     * @var array<class-string, NodeInterface>
     */
    protected array $eventNodeMap = [];

    protected ?Event $startEvent = null;

    protected ?ListenerRegistry $listeners = null;

    protected ?string $runId = null;

    /** @var TState|null */
    protected ?WorkflowState $state;

    /**
     * signal() must wait for run() or events() before delivery.
     * Keep the event name here until then.
     */
    protected ?string $stagedSignalName = null;

    /**
     * The waiting node needs the data supplied to signal().
     * Keep it with the pending signal until delivery.
     *
     * @var array<string, mixed>
     */
    protected array $stagedSignalPayload = [];

    /**
     * resume() must keep its inputs until execution, along with any
     * run/attempt checks that prevent delivery to a changed run.
     *
     * @var array{inputs: list<ResumeInput>, runId: string|null, executionAttempt: int|null}|null
     */
    protected ?array $stagedInputs = null;

    /**
     * A new Agent message must not recover the previous failed turn.
     * Forces a fresh execution for chat(), stream(), and structured().
     */
    protected bool $forceNewRun = false;

    /**
     * @param TState|null $state
     * @throws WorkflowException
     */
    public function __construct(
        protected ?string $workflowId = null,
        ?WorkflowState $state = null,
    ) {
        $this->state = $state;
        $this->exporter = new ConsoleExporter();

        $this->addGlobalMiddleware($this->globalMiddleware());
        foreach ($this->middleware() as $node => $middleware) {
            $middleware = is_array($middleware) ? $middleware : [$middleware];
            $this->addMiddleware($node, $middleware);
        }
    }

    public static function make(...$arguments): static
    {
        $class = static::class;
        return new $class(...$arguments);
    }

    /**
     * Called by the executor once per segment, after ignition is resolved.
     *
     * @throws WorkflowException
     */
    public function bootstrap(): void
    {
        $this->loadEventNodeMap();
        $this->validate();
    }

    /**
     * @throws WorkflowException
     */
    protected function loadEventNodeMap(): void
    {
        $this->eventNodeMap = [];
        $signature = new NodeSignature();

        foreach ($this->getNodes() as $node) {
            if (!$node instanceof NodeInterface) {
                throw new WorkflowException('All nodes must implement ' . NodeInterface::class);
            }

            $eventClass = $signature->eventClass($node);

            if (isset($this->eventNodeMap[$eventClass])) {
                throw new WorkflowException("Node for event {$eventClass} already exists");
            }

            $this->eventNodeMap[$eventClass] = $node;
        }
    }

    /**
     * @throws WorkflowException
     */
    protected function validate(): void
    {
        $startEvent = $this->getStartEvent();
        $startEventClass = $startEvent::class;

        if (!isset($this->eventNodeMap[$startEventClass])) {
            throw new WorkflowException('No nodes found that handle ' . $startEventClass);
        }
    }

    public function makeIgnition(string $runId): Ignition
    {
        return new Ignition($runId, $this->getStartEvent(), $this->ignitionContext());
    }

    public function adoptIgnition(Ignition $ignition): void
    {
        // An already-set start event wins: on a same-instance segment the
        // local state and the record are identical.
        if ($this->startEvent instanceof \NeuronAI\Workflow\Events\Event) {
            return;
        }

        $this->setStartEvent($this->restoreEvent($ignition->startEvent));
        $this->applyIgnitionContext($ignition->context);
    }

    /**
     * Subclass hook: run context persisted into the ignition record. Empty by
     * default — the engine never learns what a thread or a tenant is.
     *
     * @return array<string, mixed>
     */
    protected function ignitionContext(): array
    {
        return [];
    }

    /**
     * Subclass hook: the read side of ignitionContext(), applied when a blank
     * process adopts a run.
     *
     * @param array<string, mixed> $context
     */
    protected function applyIgnitionContext(array $context): void
    {
    }

    final public function getStartEvent(): Event
    {
        return $this->startEvent ??= $this->startEvent();
    }

    public function setStartEvent(Event $event): static
    {
        $this->startEvent = $event;
        return $this;
    }

    protected function startEvent(): Event
    {
        return new StartEvent();
    }

    public function addNode(NodeInterface $node): static
    {
        $this->nodes[] = $node;
        return $this;
    }

    /**
     * @param NodeInterface[] $nodes
     */
    public function addNodes(array $nodes): static
    {
        foreach ($nodes as $node) {
            $this->addNode($node);
        }
        return $this;
    }

    /**
     * @return NodeInterface[]
     */
    protected function getNodes(): array
    {
        return array_merge($this->nodes(), $this->nodes);
    }

    /**
     * @return NodeInterface[]
     */
    protected function nodes(): array
    {
        return [];
    }

    public function getEventNodeMap(): array
    {
        return $this->eventNodeMap;
    }

    /**
     * @throws WorkflowException if no node is registered for the given event class
     */
    public function getNodeForEvent(string $eventClass): NodeInterface
    {
        if (!isset($this->eventNodeMap[$eventClass])) {
            throw new WorkflowException(
                "No node found that handle event: " . $eventClass
            );
        }

        return $this->eventNodeMap[$eventClass];
    }

    /**
     * A plain workflow has no transient capability to restore — subclasses
     * whose events carry live objects (e.g. Agent's tools) override this.
     */
    public function restoreEvent(Event $event): Event
    {
        return $event;
    }

    public function restoreState(WorkflowState $state): WorkflowState
    {
        return $state;
    }

    /**
     * The workflow ID, also the continuation handle. Null before the first
     * run segment: identity is assigned by the executor, never at
     * construction.
     */
    public function getWorkflowId(): ?string
    {
        return $this->workflowId;
    }

    /**
     * The current run's generation stamp — observability identity, never the
     * continuation handle. A fresh ignition at a reused workflow ID stamps a
     * new one; the workflow ID stays.
     */
    public function getRunId(): ?string
    {
        return $this->runId;
    }

    /**
     * Adopt the identity resolved by the executor: the workflow ID is stable
     * across every run of this instance, the runId is re-stamped per run.
     */
    public function adoptIdentity(string $workflowId, string $runId): void
    {
        $this->workflowId = $workflowId;
        $this->runId = $runId;
    }

    /**
     * The business key this workflow wants as its workflow ID (e.g. the
     * Agent's threadId). Null lets the engine generate one — the run stays
     * continuable through {@see getWorkflowId()}, just not findable by a
     * business key.
     */
    public function workflowId(): ?string
    {
        return null;
    }

    /**
     * Opt into the execution lease: while a run is executing, the engine
     * renews the lease deadline inside __control with every step commit, and an inputless continuation
     * arriving while the lease is fresh is refused — it would
     * probably duplicate a live process, not revive a dead one. Suspension,
     * failure, and completion all clear the deadline, so only a process killed
     * with no chance to commit (memory limit, timeout, OOM kill) leaves it
     * held, and the next ignition supersedes it once it expires. Pick $seconds
     * well above the longest silent stretch between step boundaries (a
     * slow provider or tool call): a too-short lease revives runs that are merely
     * slow. Null disables the lease; the default comes from leaseTimeout():
     * null for a plain Workflow, ten minutes for an Agent.
     */
    public function setLeaseTimeout(?int $seconds): static
    {
        $this->leaseTimeout = $this->validateLeaseTimeout($seconds);
        $this->leaseTimeoutConfigured = true;
        return $this;
    }

    final public function getLeaseTimeout(): ?int
    {
        if (!$this->leaseTimeoutConfigured) {
            $this->setLeaseTimeout($this->leaseTimeout());
        }

        return $this->leaseTimeout;
    }

    protected function leaseTimeout(): ?int
    {
        return null;
    }

    protected function validateLeaseTimeout(?int $seconds): ?int
    {
        if ($seconds !== null && $seconds < 1) {
            throw new WorkflowException('Lease timeout must be a positive number of seconds or null.');
        }

        return $seconds;
    }

    /**
     * Opt into replayable completion for a platform-managed invocation. The
     * default remains immediate cleanup for manually driven workflows.
     */
    public function retainCompletionUntilAcknowledged(bool $retain = true): static
    {
        $this->retainCompletion = $retain;
        return $this;
    }

    final public function shouldRetainCompletionUntilAcknowledged(): bool
    {
        return $this->retainCompletion;
    }

    /**
     * Execute the staged operation, or start/recover a failed run by default.
     *
     * @return TState
     * @throws WorkflowException
     * @throws Throwable
     */
    public function run(): WorkflowState
    {
        return $this->consume($this->events());
    }

    /**
     * Stage an addressed continuation. Empty inputs recover or process due timers.
     *
     * @param list<ResumeInput> $inputs
     * @throws WorkflowException
     */
    public function resume(
        array $inputs = [],
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): static {
        $this->assertNoStagedOperation();
        $this->stagedInputs = [
            'inputs' => $inputs,
            'runId' => $expectedRunId,
            'executionAttempt' => $expectedExecutionAttempt,
        ];
        return $this;
    }

    /**
     * @throws WorkflowException
     */
    protected function assertNoStagedOperation(): void
    {
        if ($this->stagedSignalName !== null) {
            throw new WorkflowException("Signal '{$this->stagedSignalName}' is already staged for this workflow.");
        }
        if ($this->stagedInputs !== null || $this->forceNewRun) {
            throw new WorkflowException('An execution operation is already staged for this workflow.');
        }
    }

    /**
     * Translate and stage inputs for the next run() or events() continuation.
     *
     * @param array<array-key, mixed> $payload
     * @throws InputTranslationException
     * @throws WorkflowException
     */
    public function submitInputs(array $payload, InputTranslatorInterface $translator): static
    {
        $this->assertNoStagedOperation();

        $run = $this->getExecutor()->inspect($this);
        if ($run === null) {
            throw new InputTranslationException('There is no persisted run to continue.');
        }

        $inputs = $translator->translate($payload, $run->interrupts);
        if ($inputs === []) {
            throw new InputTranslationException('The payload contains no matching continuation input.');
        }

        // Keep the inspected identity: another continuation may advance the run
        // between submission and execution, making these inputs stale.
        return $this->resume($inputs, $run->runId, $run->executionAttempt);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws WorkflowException
     */
    public function signal(string $name, array $payload = []): static
    {
        $this->assertNoStagedOperation();

        $this->stagedSignalName = $name;
        $this->stagedSignalPayload = $payload;
        return $this;
    }

    public function acknowledgeCompletion(string $expectedRunId): void
    {
        $this->getExecutor()->acknowledgeCompletion($this, $expectedRunId);
    }

    public function abandonRun(?string $expectedRunId = null): bool
    {
        return $this->getExecutor()->abandonRun($this, $expectedRunId);
    }

    /**
     * Stream the staged operation, or start/recover a failed run by default.
     *
     * @return Generator<int, object|string, mixed, TState>
     * @throws Throwable
     */
    public function events(): Generator
    {
        if ($this->stagedInputs !== null) {
            $continuation = $this->stagedInputs;
            $this->stagedInputs = null;
            return $this->forwardEvents($this->getExecutor()->resume(
                $this,
                $continuation['inputs'],
                $continuation['runId'],
                $continuation['executionAttempt'],
            ));
        }

        if ($this->stagedSignalName !== null) {
            $name = $this->stagedSignalName;
            $payload = $this->stagedSignalPayload;
            $this->stagedSignalName = null;
            $this->stagedSignalPayload = [];
            return $this->forwardEvents($this->getExecutor()->signal($this, $name, $payload));
        }

        $fresh = $this->forceNewRun;
        $this->forceNewRun = false;
        return $this->forwardEvents($this->getExecutor()->execute($this, fresh: $fresh));
    }

    /**
     * @return Generator<int, object|string, mixed, TState>
     * @throws Throwable
     */
    protected function forwardEvents(Generator $generator): Generator
    {
        foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->start()) as $output) {
            yield $output;
        }

        try {
            foreach ($generator as $item) {
                foreach ($this->streamOutput($item) as $output) {
                    yield $output;
                }
            }
        } catch (Throwable $e) {
            foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->error($e)) as $output) {
                yield $output;
            }
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->failed($e, $this->workflowId ?? 'unresolved'));
            throw $e;
        }

        $state = $this->getState();
        if ($state->isInterrupted()) {
            foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->suspended($state->getInterruptRequests())) as $output) {
                yield $output;
            }
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->suspended(clone $state));

            return $state;
        }

        foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->end()) as $output) {
            yield $output;
        }

        if ($state->getStatus() === WorkflowStatus::Completed) {
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->completed($state, $this->workflowId ?? 'unresolved'));
        }

        return $state;
    }

    /**
     * An InterruptEvent is the suspension terminal, never stream content: an
     * adapter encodes it through suspended() and a channel is notified
     * through suspended(), so only native pull consumers see the event itself.
     *
     * @return Generator<int, object|string>
     */
    protected function streamOutput(object $item): Generator
    {
        $adapter = $this->getStreamAdapter();
        if ($adapter instanceof StreamAdapterInterface) {
            if ($item instanceof InterruptEvent) {
                return;
            }

            foreach ($adapter->transform($item) as $line) {
                $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->sendLine($line));
                yield $line;
            }
            return;
        }

        if (!$item instanceof InterruptEvent) {
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->send($item));
        }
        yield $item;
    }

    /**
     * @param Closure(StreamAdapterInterface): iterable<string> $callback
     * @return Generator<int, string>
     */
    protected function adapterOutput(Closure $callback): Generator
    {
        $adapter = $this->getStreamAdapter();
        if (!$adapter instanceof StreamAdapterInterface) {
            return;
        }

        foreach ($callback($adapter) as $line) {
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->sendLine($line));
            yield $line;
        }
    }

    /**
     * Catch-report-continue: a channel error never fails the run — it is
     * dispatched as a ChannelError and delivery moves on. Circuit-breaking
     * and retry are the channel implementation's own policy, not the engine's.
     *
     * @param Closure(StreamingChannelInterface): void $callback Receives the attached channel (non-null).
     */
    protected function fireChannel(Closure $callback): void
    {
        if (!$this->getChannel() instanceof StreamingChannelInterface) {
            return;
        }

        try {
            $callback($this->getChannel());
        } catch (Throwable $e) {
            $event = new ChannelError($e);
            $event->source = $this;
            $this->getEventDispatcher()->dispatch($event);
        }
    }

    /**
     * The traversal body is lazy — it does not execute until iterated.
     *
     * @param Generator<int, object|string, mixed, TState> $generator
     * @return TState
     */
    protected function consume(Generator $generator): WorkflowState
    {
        // empty foreach is more memory efficient than iterator_to_array()
        foreach ($generator as $event) {
        }

        return $generator->getReturn();
    }
}
