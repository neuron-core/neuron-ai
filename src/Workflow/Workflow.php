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
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Exporter\ConsoleExporter;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;

use function array_merge;
use function is_array;
use function hash;
use function preg_match;
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
     * @var array<NodeInterface|Closure(ExecutionContext): NodeInterface>
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

    public function makeIgnition(string $runId, Event $event): Ignition
    {
        $context = $this->ignitionContext();
        return new Ignition($runId, $event, $context, $this->ignitionFingerprint($event, $context));
    }

    /**
     * Describe immutable start input, excluding generated transport identifiers
     * in compositions that have them. This never changes the persisted event.
     *
     * @param array<string, mixed> $context
     */
    protected function ignitionFingerprint(Event $event, array $context): string
    {
        return hash('sha256', $this->getSerializer()->serialize([$event, $context]));
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
     * @param array<NodeInterface|Closure(ExecutionContext): NodeInterface> $nodes
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
    protected function nodes(WorkflowExecution $execution): array
    {
        return [];
    }

    /**
     * A plain workflow has no transient capability to restore — subclasses
     * whose events carry live objects (e.g. Agent's tools) override this.
     */
    public function restoreEvent(Event $event, ExecutionContext $context): Event
    {
        return $event;
    }

    public function restoreState(WorkflowState $state, ExecutionContext $context): WorkflowState
    {
        return $state;
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
        $id = $this->workflowId ?? $declared;
        if ($id !== null) {
            $this->validateWorkflowId($id);
        }
        return $id;
    }

    /** Bind the instance address; a bound instance cannot change its address. */
    public function setWorkflowId(string $workflowId): static
    {
        $this->validateWorkflowId($workflowId);
        $current = $this->getWorkflowId();
        if ($current !== null && $current !== $workflowId) {
            throw new WorkflowException("This workflow is bound to '{$current}' and cannot be re-pointed to '{$workflowId}'.");
        }
        $this->workflowId = $workflowId;
        return $this;
    }

    protected function validateWorkflowId(string $workflowId): void
    {
        if (preg_match('/^(?!__)[^\\x00-\\x1F\\x7F]{1,255}$/u', $workflowId) !== 1) {
            throw new WorkflowException('Invalid workflow ID: use a nonempty address of at most 255 characters without control characters or the __ prefix.');
        }
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
    final public function getLeaseTimeout(): ?int
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

    final public function shouldRetainCompletionUntilAcknowledged(): bool
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
        return $this->getExecutor()->inspect($this);
    }

    /**
     * Prepare a fenced continuation, optionally translating an external payload.
     *
     * @param array<array-key, mixed> $payload
     * @return PendingExecution<TState>
     * @throws InputTranslationException
     */
    public function submitInputs(array $payload, ?InputTranslatorInterface $translator = null, ?string $idempotencyKey = null): PendingExecution
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
            ExecutionRequest::resume($response, $run->runId, $run->executionAttempt, $idempotencyKey),
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
            $request = ExecutionRequest::start($this->getStartEvent(), $request->runId, $request->idempotencyKey, $request->recoverFailed);
        }

        $workflowId = $this->getWorkflowId();
        if ($workflowId === null && !$request->starting) {
            throw new WorkflowException(
                'Cannot identify the run to continue: no workflow ID was provided '
                . 'and the workflow declares none.'
            );
        }
        $this->setWorkflowId($workflowId ?? UniqueIdGenerator::generateId('workflow_'));

        return yield from $this->getExecutor()->execute($this, $request);
    }

    /** @internal Construct fresh execution resources only after admission. */
    public function createExecution(ExecutionContext $context): WorkflowExecution
    {
        $execution = $this->buildGraph($context);
        $execution->setOutput($this->resolveStreamAdapter($context), $this->resolveChannel($context));
        return $execution;
    }

    /**
     * @throws WorkflowException
     */
    protected function buildGraph(ExecutionContext $context): WorkflowExecution
    {
        $execution = $this->execution($context);
        $execution->bootstrap(array_merge($this->nodes($execution), array_map(static fn (NodeInterface|Closure $node): NodeInterface => $node instanceof Closure ? $node($context) : clone $node, $this->nodes)));
        return $execution;
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

    protected function execution(ExecutionContext $context): WorkflowExecution
    {
        return new WorkflowExecution(
            $context,
            $this,
            $this->newState(),
            $this->executionMiddleware(),
            $this->executionGlobalMiddleware(),
        );
    }

    public function acknowledgeCompletion(string $expectedRunId): void
    {
        $this->getExecutor()->acknowledgeCompletion($this, $expectedRunId);
    }

    public function abandonRun(?string $expectedRunId = null, ?int $expectedExecutionAttempt = null): bool
    {
        return $this->getExecutor()->abandonRun($this, $expectedRunId, $expectedExecutionAttempt);
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
