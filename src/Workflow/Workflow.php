<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Closure;
use Generator;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ChannelError;
use NeuronAI\Observability\ListenerRegistry;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
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
     * @var array{payload: array<string, mixed>|null, runId: string|null, executionAttempt: int|null}|null
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
     * Answer the current interruption. Omit the payload to recover or process its deadline.
     * An empty array is an answer, while null supplies no answer.
     *
     * @param array<string, mixed>|null $payload
     * @throws WorkflowException
     */
    public function resume(
        ?array $payload = null,
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): static {
        $this->assertNoStagedOperation();
        $this->stagedInputs = [
            'payload' => $payload,
            'runId' => $expectedRunId,
            'executionAttempt' => $expectedExecutionAttempt,
        ];
        return $this;
    }

    /**
     * Answer the current interruption only if its event name matches.
     *
     * @param array<string, mixed> $payload
     * @throws WorkflowException
     */
    public function signal(string $event, array $payload = []): static
    {
        $this->assertNoStagedOperation();
        $this->stagedSignalName = $event;
        $this->stagedSignalPayload = $payload;
        return $this;
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
        if (!$run instanceof WorkflowRunSnapshot) {
            throw new InputTranslationException('There is no persisted run to continue.');
        }

        if ($run->interrupt === null) {
            throw new InputTranslationException('There is no current interruption to answer.');
        }
        $response = $translator->translate($payload, $run->interrupt);

        // Keep the inspected identity: another continuation may advance the run
        // between submission and execution, making these inputs stale.
        return $this->resume($response, $run->runId, $run->executionAttempt);
    }

    /**
     * Stream the staged operation, or start/recover a failed run by default.
     *
     * @return Generator<int, object, mixed, TState>
     * @throws Throwable
     */
    public function events(): Generator
    {
        if ($this->stagedInputs !== null) {
            $continuation = $this->stagedInputs;
            $this->stagedInputs = null;
            return $this->forwardEvents($this->getExecutor()->resume(
                $this,
                $continuation['payload'],
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
     * @return Generator<int, object, mixed, TState>
     * @throws Throwable
     */
    protected function forwardEvents(Generator $generator): Generator
    {
        $this->getStreamAdapter()?->reset();

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
            $this->abortExecution($generator, $e);
            foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->error($e)) as $output) {
                yield $output;
            }
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->failed($e, $this->workflowId ?? 'unresolved'));
            throw $e;
        }

        $state = $this->getState();
        if ($state->isInterrupted()) {
            foreach ($this->adapterOutput(fn (StreamAdapterInterface $adapter): iterable => $adapter->suspended($state->getInterruptRequest())) as $output) {
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

    public function acknowledgeCompletion(string $expectedRunId): void
    {
        $this->getExecutor()->acknowledgeCompletion($this, $expectedRunId);
    }

    public function abandonRun(?string $expectedRunId = null): bool
    {
        return $this->getExecutor()->abandonRun($this, $expectedRunId);
    }

    /**
     * A failure raised on this side of the executor boundary (an adapter, the
     * channel error reporting) would only destroy the suspended executor
     * generator, and destruction runs finally blocks but never catch blocks:
     * the run would stay marked running under its lease. Throwing the failure
     * into the generator lets the executor settle the run as failed first,
     * exactly as it does for a failing node. An executor may keep yielding
     * while it settles concurrent branches, so the generator is drained.
     */
    protected function abortExecution(Generator $generator, Throwable $e): void
    {
        if (!$generator->valid()) {
            return;
        }

        try {
            $generator->throw($e);
            while ($generator->valid()) {
                $generator->next();
            }
        } catch (Throwable) {
            // The executor rethrows the failure once the run is marked failed.
        }
    }

    /**
     * Native output stays on the pull path: a channel carries only the
     * adapter's protocol events. An InterruptEvent is the suspension
     * terminal, never stream content: an adapter encodes it through
     * suspended() and a channel is notified through suspended(), so only
     * native pull consumers see the event itself.
     *
     * @return Generator<int, object>
     */
    protected function streamOutput(object $item): Generator
    {
        $adapter = $this->getStreamAdapter();
        if (!$adapter instanceof StreamAdapterInterface) {
            yield $item;
            return;
        }

        if ($item instanceof InterruptEvent) {
            return;
        }

        foreach ($adapter->transform($item) as $event) {
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->send($event));
            yield $event;
        }
    }

    /**
     * @param Closure(StreamAdapterInterface): iterable<ProtocolEvent> $callback
     * @return Generator<int, ProtocolEvent>
     */
    protected function adapterOutput(Closure $callback): Generator
    {
        $adapter = $this->getStreamAdapter();
        if (!$adapter instanceof StreamAdapterInterface) {
            return;
        }

        foreach ($callback($adapter) as $event) {
            $this->fireChannel(fn (StreamingChannelInterface $channel) => $channel->send($event));
            yield $event;
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
            $this->reportChannelError($e);
        }
    }

    /**
     * Reporting follows the executor's observability policy: a listener that
     * fails is itself reported as an AgentError, and a failure of that report
     * is dropped, so monitoring can never turn a delivered stream into a
     * failed run.
     */
    protected function reportChannelError(Throwable $e): void
    {
        $event = new ChannelError($e);
        $event->source = $this;

        try {
            $this->getEventDispatcher()->dispatch($event);
        } catch (Throwable $listenerFailure) {
            $error = new AgentError($listenerFailure, false);
            $error->source = $this;

            try {
                $this->getEventDispatcher()->dispatch($error);
            } catch (Throwable) {
                // Monitoring failures must not change Workflow execution.
            }
        }
    }

    /**
     * The traversal body is lazy — it does not execute until iterated.
     *
     * @param Generator<int, object, mixed, TState> $generator
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
