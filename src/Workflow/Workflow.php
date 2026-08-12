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
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use Throwable;

use function array_merge;
use function is_array;

/**
 * @method static static make(?string $address = null, ?WorkflowState $state = null)
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
        protected ?string $address = null,
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
     * Ignite a new run at the address. Never adopts a run already in flight
     * there — that is refused loudly; continue it with {@see resume()}.
     *
     * @throws WorkflowException
     * @throws Throwable
     */
    public function run(): WorkflowState
    {
        return $this->consume($this->events());
    }

    /**
     * Continue the run in flight at the address, delivering the payload only
     * if one is given: null revives without delivering anything (a
     * still-suspended run re-emits its request, a crashed step retries),
     * while an empty array delivers an explicitly empty answer.
     *
     * @param array<string, mixed>|null $payload The delivered answer, or null to deliver nothing.
     * @param bool $timedOut True when the resume was a deadline elapsing.
     * @throws WorkflowException
     * @throws Throwable
     */
    public function resume(?array $payload = null, bool $timedOut = false): WorkflowState
    {
        return $this->consume($this->events($payload, $timedOut, true));
    }

    /**
     * The single streaming entry point behind run() and resume(). A non-null
     * payload implies a continuation even when $resuming is not set.
     *
     * @param array<string, mixed>|null $payload The delivered answer on a continuation; null otherwise.
     * @param bool $resuming True to continue without delivering a payload (revive).
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws WorkflowException
     * @throws Throwable
     */
    public function events(?array $payload = null, bool $timedOut = false, bool $resuming = false): Generator
    {
        $resuming = $resuming || $payload !== null;
        $generator = $this->getExecutor()->execute($this, $payload, $timedOut, $resuming);

        // Open the protocol stream before any output, mirroring the pull
        // path's eager start so both emit the same framing timing.
        $this->fireAdapter(fn (StreamAdapterInterface $a): iterable => $a->start());

        try {
            // The single delivery choke point: every yielded item feeds the
            // channel (push) before it reaches the caller (pull). The
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
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->failed($e, $this->address ?? 'unresolved'));
            throw $e;
        }

        // Close the stream on every clean terminal (completion or suspension).
        $this->fireAdapter(fn (StreamAdapterInterface $a): iterable => $a->end());

        $state = $this->getState();
        $request = $state->getInterruptRequest();

        if ($request instanceof InterruptRequest) {
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->suspended($request, $this->address ?? 'unresolved'));
        } else {
            $this->fireChannel(fn (StreamingChannelInterface $ch) => $ch->completed($state, $this->address ?? 'unresolved'));
        }

        return $state;
    }

    /**
     * Deliver one yielded item to the channel: as protocol lines when an
     * adapter is attached, as the native chunk otherwise.
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
     * Drain adapter-produced protocol frames to the channel as sendLine()
     * lines, under fireChannel's catch-report-continue guard.
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
     * The address, also the continuation handle. Null before the first run
     * segment: identity is assigned by the executor, never at construction.
     */
    public function getAddress(): ?string
    {
        return $this->address;
    }

    /**
     * The current run's generation stamp — observability identity, never the
     * continuation handle. A fresh ignition at a reused address stamps a new
     * one; the address stays.
     */
    public function getRunId(): ?string
    {
        return $this->runId;
    }

    /**
     * Adopt the identity resolved by the executor: the address is stable
     * across every run of this instance, the runId is re-stamped per run.
     */
    public function adoptIdentity(string $address, string $runId): void
    {
        $this->address = $address;
        $this->runId = $runId;
    }

    /**
     * The business key this workflow wants as its address (e.g. the Agent's
     * threadId). Null lets the engine generate one — the run stays
     * continuable through {@see getAddress()}, just not business-addressable.
     */
    public function address(): ?string
    {
        return null;
    }

    /**
     * Opt into the execution lease: while a run is executing, the engine
     * heartbeats a lease record at its address, and a resume() arriving
     * while the lease is fresher than $seconds is refused — it would
     * probably duplicate a live process, not revive a dead one. Suspension,
     * failure, and completion all release the lease, so only a violent
     * crash (no chance to write anything) leaves it held. Pick $seconds
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
