<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Closure;
use Generator;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\ChannelError;
use NeuronAI\Observability\ListenerRegistry;
use NeuronAI\Observability\ObserverAdapter;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Observability\WorkflowEventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Resume\ResumeInput;
use Throwable;

use function array_merge;
use function is_array;

/**
 * @method static static make(?string $workflowId = null, ?WorkflowState $state = null)
 */
class Workflow implements WorkflowInterface, WorkflowRuntimeInterface
{
    use StaticConstructor;
    use HandleMiddleware;
    use ResolveState;
    use HandleComponents;

    /**
     * @var NodeInterface[]
     */
    protected array $nodes = [];

    /**
     * @var array<class-string, NodeInterface>
     */
    protected array $eventNodeMap = [];

    protected ExporterInterface $exporter;

    protected Event $startEvent;

    protected ?ListenerRegistry $listeners = null;

    protected ?EventDispatcherInterface $dispatcher = null;

    protected ?EventDispatcherInterface $externalDispatcher = null;

    protected ?string $runId = null;

    /**
     * @throws WorkflowException
     */
    public function __construct(
        protected ?string $workflowId = null,
        protected ?WorkflowState   $state = null,
    ) {
        $this->exporter = new ConsoleExporter();

        $this->addGlobalMiddleware($this->globalMiddleware());
        foreach ($this->middleware() as $node => $middleware) {
            $middleware = is_array($middleware) ? $middleware : [$middleware];
            $this->addMiddleware($node, $middleware);
        }
    }

    /**
     * @deprecated Use subscribe() with a PSR-14 listener instead. Will be
     *             removed in the next major version.
     */
    public function observe(ObserverInterface $observer): static
    {
        return $this->subscribe(ObservabilityEvent::class, new ObserverAdapter($observer));
    }

    /**
     * Matching is instanceof-based: subscribing to ObservabilityEvent
     * receives every event.
     *
     * @param class-string $eventClass
     */
    public function subscribe(string $eventClass, callable $listener): static
    {
        $this->listenerRegistry()->listen($eventClass, $listener);
        return $this;
    }

    /**
     * Forward this workflow's events to an external PSR-14 dispatcher.
     * Listeners registered via observe()/subscribe() run first.
     */
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): static
    {
        $this->externalDispatcher = $dispatcher;
        // Rebuild the resolved dispatcher with the new forward target.
        $this->dispatcher = null;
        return $this;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcher ??= new WorkflowEventDispatcher(
            $this->listenerRegistry(),
            $this->externalDispatcher,
        );
    }

    protected function listenerRegistry(): ListenerRegistry
    {
        return $this->listeners ??= new ListenerRegistry();
    }

    /**
     * Catch-report-continue: a channel error never fails the run — it is
     * dispatched as a ChannelError and delivery moves on. Circuit-breaking
     * and retry are the channel implementation's own policy, not the engine's.
     *
     * @param Closure(StreamingChannelInterface): void $op Receives the attached channel (non-null).
     */
    protected function fireChannel(Closure $op): void
    {
        if (!$this->getChannel() instanceof StreamingChannelInterface) {
            return;
        }

        try {
            $op($this->getChannel());
        } catch (Throwable $e) {
            $event = new ChannelError($e);
            $event->source = $this;
            $this->getEventDispatcher()->dispatch($event);
        }
    }

    /**
     * Ignite a new run under the workflow ID. Never adopts a run already in
     * flight there — that is refused loudly; continue it with {@see resume()}.
     *
     * @throws WorkflowException
     * @throws Throwable
     */
    public function run(): WorkflowState
    {
        return $this->consume($this->events());
    }

    /**
     * @param list<ResumeInput> $inputs
     * @throws WorkflowException
     * @throws Throwable
     */
    public function resume(
        array $inputs = [],
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): WorkflowState {
        return $this->consume($this->events($inputs, $expectedRunId, $expectedExecutionAttempt));
    }

    public function acknowledgeCompletion(string $expectedRunId): void
    {
        $this->getExecutor()->acknowledgeCompletion($this, $expectedRunId);
    }

    /**
     * @param list<ResumeInput>|null $inputs
     * @return Generator<int, object|string, mixed, WorkflowState>
     */
    public function events(
        ?array $inputs = null,
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): Generator {
        $continuing = $inputs !== null
            || $expectedRunId !== null
            || $expectedExecutionAttempt !== null;
        $generator = $continuing
            ? $this->getExecutor()->resume(
                $this,
                $inputs ?? [],
                $expectedRunId,
                $expectedExecutionAttempt,
            )
            : $this->getExecutor()->execute($this);

        return $this->forwardEvents($generator);
    }

    /**
     * @return Generator<int, object|string, mixed, WorkflowState>
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
            foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->end()) as $output) {
                yield $output;
            }
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->failed($e, $this->workflowId ?? 'unresolved'));
            throw $e;
        }

        foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->end()) as $output) {
            yield $output;
        }

        $state = $this->getState();
        if ($state->isInterrupted()) {
            foreach ($state->getInterruptRequests() as $request) {
                $this->fireChannel(
                    fn (StreamingChannelInterface $ch) => $ch->suspended(
                        $request,
                        $this->workflowId ?? 'unresolved',
                    ),
                );
            }
        } elseif ($state->getStatus() === WorkflowStatus::Completed) {
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->completed($state, $this->workflowId ?? 'unresolved'));
        }

        return $state;
    }

    /**
     * @return Generator<int, object|string>
     */
    protected function streamOutput(object $item): Generator
    {
        $adapter = $this->getStreamAdapter();
        if ($adapter instanceof StreamAdapterInterface) {
            foreach ($adapter->transform($item) as $line) {
                if (!$item instanceof InterruptEvent) {
                    $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->sendLine($line));
                }
                yield $line;
            }
            return;
        }

        if (!$item instanceof InterruptEvent) {
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->send($item));
        }
        yield $item;
    }

    /**
     * @param Closure(StreamAdapterInterface): iterable<string> $frames
     * @return Generator<int, string>
     */
    protected function adapterOutput(Closure $frames): Generator
    {
        $adapter = $this->getStreamAdapter();
        if (!$adapter instanceof StreamAdapterInterface) {
            return;
        }

        foreach ($frames($adapter) as $line) {
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->sendLine($line));
            yield $line;
        }
    }

    /**
     * The traversal body is lazy — it does not execute until iterated.
     */
    protected function consume(Generator $generator): WorkflowState
    {
        foreach ($generator as $event) {
        }

        return $generator->getReturn();
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

    public function makeIgnition(string $runId): Ignition
    {
        return new Ignition($runId, $this->getStartEvent(), $this->ignitionContext());
    }

    public function adoptIgnition(Ignition $ignition): void
    {
        // An already-set start event wins: on a same-instance segment the
        // local state and the record are identical.
        if (isset($this->startEvent)) {
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
     * refreshes the lease deadline inside __control, and an inputless resume
     * arriving while the lease is fresh is refused — it would
     * probably duplicate a live process, not revive a dead one. Suspension,
     * failure, and completion all clear the deadline, so only a violent
     * crash (no chance to commit control) leaves it held. Pick $seconds
     * well above the longest silent stretch between step boundaries (a
     * slow provider call): a too-short lease revives runs that are merely
     * slow. Null (the default) disables the lease entirely.
     */
    public function setLeaseTimeout(?int $seconds): static
    {
        $this->leaseTimeout = $seconds;
        return $this;
    }

    final public function getLeaseTimeout(): ?int
    {
        return $this->leaseTimeout ??= $this->leaseTimeout();
    }

    protected function leaseTimeout(): ?int
    {
        return null;
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
     * @throws WorkflowException
     */
    public function export(): string
    {
        if ($this->eventNodeMap === []) {
            $this->bootstrap();
        }

        return $this->exporter->export($this->eventNodeMap);
    }

    public function setExporter(ExporterInterface $exporter): static
    {
        $this->exporter = $exporter;
        return $this;
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

}
