<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Closure;
use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\ChannelError;
use NeuronAI\Observability\ListenerRegistry;
use NeuronAI\Observability\ObserverAdapter;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Observability\WorkflowEventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Channel\ChannelInterface;
use NeuronAI\Workflow\Channel\NullChannel;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\NullScheduler;
use NeuronAI\Workflow\Executor\SchedulerInterface;
use NeuronAI\Workflow\Executor\StepEngineInterface;
use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Throwable;

use function array_merge;
use function is_array;
use function uniqid;

/**
 * @method static static make(?string $runId = null, ?WorkflowState $state = null)
 */
class Workflow implements WorkflowInterface, WorkflowRuntimeInterface
{
    use StaticConstructor;
    use HandleMiddleware;
    use ResolveState;

    /**
     * @var NodeInterface[]
     */
    protected array $nodes = [];

    /**
     * @var array<class-string, NodeInterface>
     */
    protected array $eventNodeMap = [];

    protected ExporterInterface $exporter;

    protected string $runId;

    protected Event $startEvent;

    protected ?WorkflowExecutorInterface $executor = null;

    protected ?PersistenceInterface $persistence = null;

    protected ?SchedulerInterface $scheduler = null;

    protected ?ChannelInterface $channel = null;

    protected ?ListenerRegistry $listeners = null;

    protected ?EventDispatcherInterface $dispatcher = null;

    protected ?EventDispatcherInterface $externalDispatcher = null;

    /**
     * @throws WorkflowException
     */
    public function __construct(
        ?string $runId = null,
        protected ?WorkflowState   $state = null,
    ) {
        $this->exporter = new ConsoleExporter();
        $this->runId = $runId ?? uniqid('workflow_');

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
    public function observe(ObserverInterface $observer): WorkflowInterface
    {
        return $this->subscribe(ObservabilityEvent::class, new ObserverAdapter($observer));
    }

    /**
     * Register a PSR-14 listener for a specific event class. Matching is
     * instanceof-based, so subscribing to ObservabilityEvent receives every event.
     *
     * @param class-string $eventClass
     */
    public function subscribe(string $eventClass, callable $listener): WorkflowInterface
    {
        $this->listenerRegistry()->listen($eventClass, $listener);
        return $this;
    }

    /**
     * Forward this workflow's events to an external PSR-14 dispatcher (e.g. a
     * host framework's event dispatcher). Listeners registered via observe()
     * and subscribe() keep working; the event is forwarded after them.
     */
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): WorkflowInterface
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
     * Set a custom executor for this workflow.
     */
    public function setExecutor(WorkflowExecutorInterface $executor): static
    {
        $this->executor = $executor;
        return $this;
    }

    /**
     * Enable durability by providing a persistence backend.
     */
    public function setPersistence(PersistenceInterface $persistence): static
    {
        $this->persistence = $persistence;
        return $this;
    }

    protected function persistence(): PersistenceInterface
    {
        return $this->persistence ??= new InMemoryPersistence();
    }

    /**
     * Provide a scheduler to coordinate wakeups for suspended workflows.
     *
     * Defaults to an inert NullScheduler (caller-driven resume), matching the
     * out-of-the-box behavior.
     */
    public function setScheduler(SchedulerInterface $scheduler): static
    {
        $this->scheduler = $scheduler;
        return $this;
    }

    protected function scheduler(): SchedulerInterface
    {
        return $this->scheduler ??= new NullScheduler();
    }

    /**
     * Where in-flight output is delivered while the run is in flight (a
     * websocket, SSE sink, ...). Default is inert (NullChannel): delivery
     * goes nowhere and today's behavior is unchanged.
     */
    public function setChannel(ChannelInterface $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    protected function channel(): ChannelInterface
    {
        return $this->channel ??= new NullChannel();
    }

    /**
     * Run one channel call under the catch-report-continue policy: a channel
     * error never fails the run — every failure is dispatched as a ChannelError
     * and delivery moves on. Circuit-breaking (stop trying after N failures,
     * retry, back off) is the channel implementation's own policy, not the
     * engine's: the channel is the code that understands its transport's
     * failure semantics, and it can short-circuit its own send().
     */
    protected function fireChannel(Closure $op): void
    {
        if ($this->channel === null || $this->channel instanceof NullChannel) {
            return;
        }

        try {
            $op();
        } catch (Throwable $e) {
            $event = new ChannelError($e);
            $event->source = $this;
            $this->getEventDispatcher()->dispatch($event);
        }
    }

    /**
     * Resolve the executor, creating a default if none was configured.
     *
     * The local executor depends on a LocalStepEngine (which owns persistence),
     * not on persistence directly — so the engine is constructed here from the
     * configured persistence and injected.
     */
    protected function resolveExecutor(): WorkflowExecutorInterface
    {
        return $this->executor ??= new WorkflowExecutor(
            new LocalStepEngine($this->persistence()),
            $this->scheduler(),
        );
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
        $this->resolveIgnition($payload);
        $this->bootstrap();

        $generator = $this->resolveExecutor()->execute($this, $payload, $timedOut);

        try {
            // The single delivery choke point: every yielded item feeds the
            // channel (push) before it reaches the caller (pull), so a stalled
            // pull consumer never delays push consumers within an item. The
            // InterruptEvent terminal is delivered via suspended() instead —
            // pull consumers still receive it unchanged.
            foreach ($generator as $item) {
                if (!$item instanceof InterruptEvent) {
                    $this->fireChannel(fn () => $this->channel()->send($item));
                }
                yield $item;
            }
        } catch (Throwable $e) {
            $this->fireChannel(fn () => $this->channel()->failed($e, $this->runId));
            throw $e;
        }

        $state = $this->resolveState();
        $request = $state->getInterruptRequest();

        if ($request instanceof InterruptRequest) {
            $this->fireChannel(fn () => $this->channel()->suspended($request, $this->runId));
        } else {
            $this->fireChannel(fn () => $this->channel()->completed($state, $this->runId));
        }

        return $state;
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
     * Bootstrap the workflow (load event-node map, validate).
     *
     * @throws WorkflowException
     */
    protected function bootstrap(): static
    {
        $this->loadEventNodeMap();
        $this->validate();
        return $this;
    }

    /**
     * Reserved step id for the ignition record — the persisted snapshot of how
     * the run was ignited (start event + context bag). Node steps are
     * `NodeClass-index` and memo steps `stepId::name`, so it can never collide.
     */
    protected const IGNITION_STEP_ID = '__ignition';

    /**
     * Make the run self-describing before anything else happens: a fresh
     * ignition writes the record; a blank process adopts the persisted start
     * event and context; a wake of a run that was never durably started fails
     * loudly. Runs BEFORE bootstrap() — adoption must precede validate()'s
     * materialization of the default start event, and subclasses apply their
     * ignition context (e.g. thread identity) before nodes are constructed.
     *
     * @param array<string, mixed>|null $payload Null to start/replay; the delivered payload on a wake.
     * @throws WorkflowException
     */
    protected function resolveIgnition(?array $payload): void
    {
        // Key the engine to this run for the ignition IO below; the executor
        // stages the payload itself with its own prepareExecution() call.
        $this->stepEngine()->prepareExecution($this->runId);

        $ignition = $this->loadIgnition();

        if ($ignition === null) {
            if ($payload !== null) {
                throw new WorkflowException(
                    "Cannot wake run {$this->runId}: no ignition record — the run "
                    . "was never durably started, or already completed."
                );
            }

            $this->writeIgnition();
            return;
        }

        if (!isset($this->startEvent)) {
            // Blank process: adopt. When the start event is already set (same
            // instance, or explicitly configured) local state wins — on a
            // same-instance segment the two are identical.
            $event = $ignition['startEvent'];
            if ($event instanceof Event) {
                $this->setStartEvent($this->restoreEventNode($event));
            }

            $context = $ignition['context'] ?? [];
            $this->applyIgnitionContext(is_array($context) ? $context : []);
        }
    }

    /**
     * Persist how this run was ignited: its start event plus the subclass's
     * context bag. Written on the first segment, swept with the run's steps on
     * clean completion — a completed run cannot be woken.
     */
    protected function writeIgnition(): void
    {
        $this->stepEngine()->saveStep(self::IGNITION_STEP_ID, new StepResult(
            stepId: self::IGNITION_STEP_ID,
            output: [
                'version' => 1,
                'startEvent' => $this->resolveStartEvent(),
                'context' => $this->ignitionContext(),
            ],
        ));
    }

    /**
     * @return array{version: int, startEvent: Event, context: array<string, mixed>}|null
     */
    protected function loadIgnition(): ?array
    {
        $output = $this->stepEngine()->getStep(self::IGNITION_STEP_ID)?->getOutput();

        /** @var array{version: int, startEvent: Event, context: array<string, mixed>}|null */
        return is_array($output) ? $output : null;
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
     * The step engine that owns this run's durable records, routed through the
     * executor: a custom executor brings its own engine (and store), so the
     * workflow's configured persistence may not be where steps actually live.
     */
    protected function stepEngine(): StepEngineInterface
    {
        return $this->resolveExecutor()->getStepEngine();
    }

    /**
     * Get the resolved start event for the workflow.
     */
    public function getStartEvent(): Event
    {
        return $this->resolveStartEvent();
    }

    /**
     * Set a custom start event with initial data.
     */
    public function setStartEvent(Event $event): WorkflowInterface
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

    /**
     * Resolve the start event for the workflow.
     */
    protected function resolveStartEvent(): Event
    {
        return $this->startEvent ??= $this->startEvent();
    }

    public function addNode(NodeInterface $node): Workflow
    {
        $this->nodes[] = $node;
        return $this;
    }

    /**
     * @param NodeInterface[] $nodes
     */
    public function addNodes(array $nodes): Workflow
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
    public function restoreEventNode(Event $event): Event
    {
        return $event;
    }

    /**
     * The unique identifier of this workflow run — also the resume handle: pass
     * it back to the constructor to reattach to a suspended run.
     */
    public function getRunId(): string
    {
        return $this->runId;
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

    public function setExporter(ExporterInterface $exporter): Workflow
    {
        $this->exporter = $exporter;
        return $this;
    }

    /**
     * @throws WorkflowException
     */
    protected function validate(): void
    {
        $startEvent = $this->resolveStartEvent();
        $startEventClass = $startEvent::class;

        if (!isset($this->eventNodeMap[$startEventClass])) {
            throw new WorkflowException('No nodes found that handle ' . $startEventClass);
        }
    }

}
