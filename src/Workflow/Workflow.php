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
use NeuronAI\Workflow\Executor\NullScheduler;
use NeuronAI\Workflow\Executor\SchedulerInterface;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\Serializer;
use Throwable;

use function array_merge;
use function is_array;

/**
 * @method static static make(?string $runId = null, ?WorkflowState $state = null)
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

    protected ?WorkflowExecutorInterface $executor = null;

    protected ?PersistenceInterface $persistence = null;

    protected ?Serializer $serializer = null;

    protected ?SchedulerInterface $scheduler = null;

    protected ?StreamingChannelInterface $channel = null;

    /**
     * Optional push-side delivery transform. When attached, yielded items are
     * run through it and delivered to the channel as protocol lines
     * (sendLine()); otherwise native chunks are delivered via send(). Null
     * means "deliver native chunks" (the default).
     */
    protected ?StreamAdapterInterface $streamAdapter = null;

    protected ?ListenerRegistry $listeners = null;

    protected ?EventDispatcherInterface $dispatcher = null;

    protected ?EventDispatcherInterface $externalDispatcher = null;

    /**
     * @throws WorkflowException
     */
    public function __construct(
        protected ?string $runId = null,
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
     * Register an observer to receive every event of this workflow instance.
     * Legacy entry point — the observer is adapted to a PSR-14 listener on
     * the ObservabilityEvent base class.
     *
     * @deprecated Use subscribe() with a PSR-14 listener instead. Will be
     *             removed in the next major version.
     */
    public function observe(ObserverInterface $observer): static
    {
        return $this->subscribe(ObservabilityEvent::class, new ObserverAdapter($observer));
    }

    /**
     * Register a PSR-14 listener for a specific event class. Matching is
     * instanceof-based, so subscribing to ObservabilityEvent receives every event.
     *
     * @param class-string $eventClass
     */
    public function subscribe(string $eventClass, callable $listener): static
    {
        $this->listenerRegistry()->listen($eventClass, $listener);
        return $this;
    }

    /**
     * Forward this workflow's events to an external PSR-14 dispatcher (e.g. a
     * host framework's event dispatcher). Listeners registered via observe()
     * and subscribe() keep working; the event is forwarded after them.
     */
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): static
    {
        $this->externalDispatcher = $dispatcher;
        // Rebuild the resolved dispatcher with the new forward target.
        $this->dispatcher = null;
        return $this;
    }

    /**
     * The PSR-14 dispatcher owned by this workflow instance. Internal
     * components (executor, nodes) emit through it.
     */
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
     * Run one channel call under the catch-report-continue policy: a channel
     * error never fails the run — every failure is dispatched as a ChannelError
     * and delivery moves on. Skipped entirely when no channel is attached.
     * Circuit-breaking (stop trying after N failures, retry, back off) is the
     * channel implementation's own policy, not the engine's: the channel is the
     * code that understands its transport's failure semantics, and it can
     * short-circuit its own send().
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
     * Start the workflow to completion, consuming the generator internally.
     * Never resumes — use {@see resume()} to deliver a payload to a suspended step.
     * @throws WorkflowException
     * @throws Throwable
     */
    public function run(): WorkflowState
    {
        return $this->consume($this->events());
    }

    /**
     * Resume a suspended workflow by delivering the inbound payload to the
     * interrupted step. Consumes the generator internally.
     *
     * @param array<string, mixed> $payload The delivered event payload (the answer).
     * @param bool $timedOut True when the resume was a deadline elapsing.
     * @throws WorkflowException
     * @throws Throwable
     */
    public function resume(array $payload = [], bool $timedOut = false): WorkflowState
    {
        return $this->consume($this->events($payload, $timedOut));
    }

    /**
     * The single streaming entry point. With no payload it starts/replays; with a
     * payload it resumes the interrupted step. {@see run()} and {@see resume()} are
     * thin wrappers that drive this generator to completion and return the state.
     *
     * @param array<string, mixed>|null $payload Null to start/replay; the delivered payload to resume.
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws WorkflowException
     * @throws Throwable
     */
    public function events(?array $payload = null, bool $timedOut = false): Generator
    {
        $generator = $this->getExecutor()->execute($this, $payload, $timedOut);

        // Open the protocol stream eagerly (start() before any output) when an
        // adapter is attached — no-op otherwise. Mirrors the pull path's eager
        // start, so push and push-via-adapter emit the same framing timing.
        $this->fireAdapter(fn (StreamAdapterInterface $a): iterable => $a->start());

        try {
            // The single delivery choke point: every yielded item feeds the
            // channel (push) before it reaches the caller (pull), so a stalled
            // pull consumer never delays push consumers within an item. The
            // InterruptEvent terminal is delivered via suspended() instead —
            // pull consumers still receive it unchanged.
            foreach ($generator as $item) {
                if (!$item instanceof InterruptEvent) {
                    $this->deliver($item);
                }
                yield $item;
            }
        } catch (Throwable $e) {
            $this->fireAdapter(fn (StreamAdapterInterface $a): iterable => $a->end());
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->failed($e, $this->runId ?? 'unresolved'));
            throw $e;
        }

        // Close the stream on every clean terminal (completion or suspension).
        $this->fireAdapter(fn (StreamAdapterInterface $a): iterable => $a->end());

        $state = $this->getState();
        $request = $state->getInterruptRequest();

        if ($request instanceof InterruptRequest) {
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->suspended($request, $this->runId ?? 'unresolved'));
        } else {
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->completed($state, $this->runId ?? 'unresolved'));
        }

        return $state;
    }

    /**
     * Deliver one yielded item to the channel: through the stream adapter (as
     * protocol lines via sendLine) when one is attached, or as the native chunk
     * (via send) otherwise. Routed through fireChannel, so an adapter/channel
     * throw costs one ChannelError and never fails the run.
     */
    protected function deliver(object $item): void
    {
        if ($this->getStreamAdapter() instanceof StreamAdapterInterface) {
            $this->fireAdapter(fn (StreamAdapterInterface $a): iterable => $a->transform($item));
        } else {
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->send($item));
        }
    }

    /**
     * Drain adapter-produced protocol frames to the channel. No-op when no
     * adapter is attached; otherwise each frame goes out as a sendLine() line
     * under fireChannel's catch-report-continue guard. `$frames` produces the
     * iterable of lines — start(), transform($item), or end() depending on the
     * call site.
     *
     * @param Closure(StreamAdapterInterface): iterable<string> $frames
     */
    protected function fireAdapter(Closure $frames): void
    {
        if (!$this->getStreamAdapter() instanceof StreamAdapterInterface) {
            return;
        }
        $this->fireChannel(function (StreamingChannelInterface $ch) use ($frames): void {
            foreach ($frames($this->getStreamAdapter()) as $line) {
                $ch->sendLine($line);
            }
        });
    }

    /**
     * Drive a generator to completion and return its state. The traversal body is
     * lazy — it does not execute until iterated.
     */
    protected function consume(Generator $generator): WorkflowState
    {
        foreach ($generator as $event) {
        }

        return $generator->getReturn();
    }

    /**
     * Bootstrap the workflow (load event-node map, validate). Called by the
     * executor once per segment, after ignition is resolved.
     *
     * @throws WorkflowException
     */
    public function bootstrap(): void
    {
        $this->loadEventNodeMap();
        $this->validate();
    }

    public function makeIgnition(): Ignition
    {
        return new Ignition($this->getStartEvent(), $this->ignitionContext());
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
     * Subclass hook: run context persisted into the ignition record alongside
     * the start event. Empty by default — the engine never learns what a
     * thread or a tenant is.
     *
     * @return array<string, mixed>
     */
    protected function ignitionContext(): array
    {
        return [];
    }

    /**
     * Subclass hook: apply a persisted ignition context when a blank process
     * adopts a run. Symmetric read side of ignitionContext().
     *
     * @param array<string, mixed> $context
     */
    protected function applyIgnitionContext(array $context): void
    {
    }

    /**
     * Get the resolved start event for the workflow.
     */
    final public function getStartEvent(): Event
    {
        return $this->startEvent ??= $this->startEvent();
    }

    /**
     * Set a custom start event with initial data.
     */
    public function setStartEvent(Event $event): static
    {
        $this->startEvent = $event;
        return $this;
    }

    /**
     * Create the default start event for the workflow.
     */
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
     * Get the node that handles a specific event type.
     *
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
     * Restore a recalled event's transient capability (see WorkflowRuntimeInterface).
     * A plain workflow has none — subclasses whose events carry live objects
     * (e.g. Agent re-seeding its tool registry) override this.
     */
    public function restoreEvent(Event $event): Event
    {
        return $event;
    }

    /**
     * The unique identifier of this workflow run — also the resume handle: pass
     * it back to the constructor to reattach to a suspended run. Null before
     * the first run segment: identity is assigned by the executor, never
     * defaulted at construction.
     */
    public function getRunId(): ?string
    {
        return $this->runId;
    }

    /**
     * Adopt the run identity resolved by the executor's identity phase
     * (fresh → generated; continuation → explicit or resolved from the
     * correlation pointer). Identity is assigned exactly once per run;
     * later segments of the same instance keep it ("local state wins").
     */
    public function adoptRunId(string $runId): void
    {
        $this->runId = $runId;
    }

    /**
     * The business/correlation key by which this run wants to be addressable
     * (e.g. the Agent's threadId). Null by default — a plain workflow declares
     * none and is unaffected by the correlation pointer.
     */
    public function correlationKey(): ?string
    {
        return null;
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
