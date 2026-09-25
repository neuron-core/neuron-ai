<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use Closure;
use Generator;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\ListenerRegistry;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;

use function array_merge;
use function is_array;
use function array_map;

/**
 * @template TState of WorkflowState = WorkflowState
 * @implements WorkflowInterface<TState>
 */
class Workflow implements WorkflowInterface
{
    use HandleMiddleware;
    /** @use ResolveState<TState> */
    use ResolveState;
    use HandleComponents;
    use HandleDispatcher;

    /**
     * @var array<NodeInterface|Closure(): NodeInterface>
     */
    protected array $nodes = [];

    protected ?Event $startEvent = null;

    protected ?ListenerRegistry $listeners = null;

    /** @var TState|null */
    protected ?WorkflowState $initialState;

    /**
     * @param TState|null $state
     */
    public function __construct(
        protected ?string $workflowId = null,
        ?WorkflowState $state = null,
    ) {
        $this->initialState = $state;
        $this->exporter = new ConsoleExporter();

    }

    public static function make(...$arguments): static
    {
        $class = static::class;
        return new $class(...$arguments);
    }

    final public function getStartEvent(): Event
    {
        return $this->startEvent ?? $this->startEvent();
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

    public function addNode(NodeInterface|Closure $node): static
    {
        $this->nodes[] = $node;
        return $this;
    }

    /**
     * @param array<NodeInterface|Closure(): NodeInterface> $nodes
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
    protected function nodes(): array
    {
        return [];
    }

    /**
     * The workflow address, resolved without initializing a run or services.
     * Only unkeyed workflows remain unidentified until execution.
     */
    public function getWorkflowId(): ?string
    {
        $declared = $this->workflowId();
        if ($declared !== null && $this->workflowId !== null && $declared !== $this->workflowId) {
            throw new WorkflowException("Misidentified run: the workflow declares workflow ID '{$declared}' but was given '{$this->workflowId}'.");
        }
        return $this->workflowId ?? $declared;
    }

    /** Bind the instance address; a bound instance cannot change its address. */
    public function setWorkflowId(string $workflowId): static
    {
        $current = $this->getWorkflowId();
        if ($current !== null && $current !== $workflowId) {
            throw new WorkflowException("This workflow is bound to '{$current}' and cannot be re-pointed to '{$workflowId}'.");
        }
        $this->workflowId = $workflowId;
        return $this;
    }

    /** The business key declared by a subclass, if any. */
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
     *
     * @throws WorkflowException
     */
    public function setLeaseTimeout(?int $seconds): static
    {
        $this->leaseTimeout = $this->validateLeaseTimeout($seconds);
        $this->leaseTimeoutConfigured = true;
        return $this;
    }

    /**
     * @throws WorkflowException
     */
    final protected function getLeaseTimeout(): ?int
    {
        if (!$this->leaseTimeoutConfigured) {
            return $this->validateLeaseTimeout($this->leaseTimeout());
        }

        return $this->leaseTimeout;
    }

    protected function leaseTimeout(): ?int
    {
        return null;
    }

    /**
     * @throws WorkflowException
     */
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

    final protected function shouldRetainCompletionUntilAcknowledged(): bool
    {
        return $this->retainCompletion;
    }

    /**
     * Execute one request eagerly, including delivery to a configured channel.
     *
     * @return TState
     * @throws WorkflowException
     */
    public function run(?ExecutionRequest $request = null): WorkflowState
    {
        return $this->consume($this->events($request));
    }

    public function inspect(): ?WorkflowRunSnapshot
    {
        $workflowId = $this->getWorkflowId();

        return $workflowId === null ? null : $this->getEngine()->inspect($workflowId);
    }

    /**
     * Prepare a fenced continuation, optionally translating an external payload.
     *
     * @param array<array-key, mixed> $payload
     * @return PendingExecution<TState>
     * @throws InputTranslationException
     */
    public function submitInputs(array $payload, ?InputTranslatorInterface $translator = null): PendingExecution
    {
        $run = $this->inspect();

        if (!$run instanceof WorkflowRunSnapshot) {
            throw new InputTranslationException('There is no persisted run to continue.');
        }

        if (!$run->interrupt instanceof InterruptRequest) {
            throw new InputTranslationException('There is no current interruption to answer.');
        }

        $response = $translator?->translate($payload, $run->interrupt) ?? $payload;

        // Capture the run and attempt: another continuation may advance the run
        // between submission and execution, making these inputs stale.
        return new PendingExecution(
            $this,
            ExecutionRequest::resume($response, $run->runId, $run->executionAttempt),
        );
    }

    /**
     * Lazily execute one request. Iteration also delivers to a configured channel.
     *
     * @return Generator<int, object, mixed, TState>
     * @throws WorkflowException
     */
    public function events(?ExecutionRequest $request = null): Generator
    {
        $request ??= ExecutionRequest::start($this->getStartEvent(), recoverFailed: true);

        if ($request->starting && $request->event() === null) {
            $request = ExecutionRequest::start($this->getStartEvent(), $request->runId, $request->recoverFailed);
        }

        $workflowId = $this->getWorkflowId();
        if ($workflowId === null && !$request->starting) {
            throw new WorkflowException(
                'Cannot identify the run to continue: no workflow ID was provided '
                . 'and the workflow declares none.'
            );
        }
        $this->setWorkflowId($workflowId ??= UniqueIdGenerator::generateId('workflow_'));

        $segment = $this->getEngine()->admit(
            $workflowId,
            $request,
            $this->newState(),
            $this->getLeaseTimeout(),
            $this->shouldRetainCompletionUntilAcknowledged(),
        );
        if ($segment instanceof WorkflowState) {
            return $segment;
        }

        // Resolved now, so a setter called while the segment runs applies to
        // the next call; the graph and the output are built after admission.
        return yield from $segment->run(
            graph: $this->graph(...),
            adapter: $this->resolveStreamAdapter(...),
            channel: $this->resolveChannel(...),
            branches: $this->getBranchRunner(),
            dispatcher: $this->getEventDispatcher(),
            source: $this,
        );
    }

    /**
     * The graph of one segment: fresh nodes and middleware, and the resources
     * they share, checked against the run's start event.
     *
     * @throws WorkflowException
     */
    final protected function graph(Event $start): Graph
    {
        return new Graph(
            $start,
            $this->resolveResources(),
            array_merge($this->nodes(), array_map(static fn (NodeInterface|Closure $node): NodeInterface => $node instanceof Closure ? $node() : clone $node, $this->nodes)),
            $this->executionMiddleware(),
            $this->executionGlobalMiddleware(),
        );
    }

    /** @return array<class-string<NodeInterface>, array<WorkflowMiddleware>> */
    protected function executionMiddleware(): array
    {
        $configured = array_map(fn (array $list): array => $this->instantiateMiddleware($list), $this->nodeMiddleware);
        foreach ($this->middleware() as $class => $list) {
            $configured[$class] = array_merge(is_array($list) ? $list : [$list], $configured[$class] ?? []);
        }
        return $configured;
    }

    /** @return array<WorkflowMiddleware> */
    protected function executionGlobalMiddleware(): array
    {
        return array_merge($this->globalMiddleware(), $this->instantiateMiddleware($this->globalMiddleware));
    }

    /** @param array<WorkflowMiddleware|Closure> $list
     * @return array<WorkflowMiddleware> */
    protected function instantiateMiddleware(array $list): array
    {
        return array_map(static fn ($item) => $item instanceof Closure ? $item() : clone $item, $list);
    }

    public function acknowledge(string $expectedRunId): void
    {
        $this->getEngine()->acknowledge($this->requireWorkflowId(), $expectedRunId);
    }

    public function abandon(?string $expectedRunId = null, ?int $expectedExecutionAttempt = null): bool
    {
        return $this->getEngine()->abandon($this->requireWorkflowId(), $expectedRunId, $expectedExecutionAttempt);
    }

    /**
     * @throws WorkflowException
     */
    protected function requireWorkflowId(): string
    {
        return $this->getWorkflowId() ?? throw new WorkflowException(
            'Cannot identify the run: no workflow ID was provided '
            . 'and the workflow declares none.'
        );
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
