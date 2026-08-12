<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\BranchEnd;
use NeuronAI\Observability\Events\BranchStart;
use NeuronAI\Observability\Events\MiddlewareEnd;
use NeuronAI\Observability\Events\MiddlewareStart;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowInterrupted;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\Serializer;
use NeuronAI\Workflow\WorkflowRuntimeInterface;
use NeuronAI\Workflow\WorkflowState;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function uniqid;

/**
 * The run lifecycle: ignition (register / adopt / refuse), bootstrap,
 * replay-based traversal, terminal handling. Owns no configuration — it reads
 * the run's context off the WorkflowRuntimeInterface at execute() time, so one
 * execution model composes with any persistence backend or scheduler.
 *
 * Replay is keyed by step id alone: ids are unique within a run, so a cached
 * completed step is always prior work and safe to skip. Interrupted steps
 * resume from the inbound payload; failed steps retry.
 *
 * This is the in-process model; AsyncExecutor runs parallel branches concurrently.
 */
class WorkflowExecutor implements WorkflowExecutorInterface
{
    /**
     * Reserved store names. IGNITION_KEY holds the run's trigger envelope in
     * its own partition, deleted with it on clean completion.
     * CORRELATION_PARTITION maps business key → most recently ignited runId;
     * its rows are never deleted — liveness is derived from the named run's
     * ignition record (see resolveIdentity).
     */
    protected const IGNITION_KEY = '__ignition';

    protected const CORRELATION_PARTITION = '__correlation';

    protected PersistenceInterface $persistence;

    protected SchedulerInterface $scheduler;

    protected Serializer $serializer;

    protected string $runId;

    /**
     * Null means fresh start or crash-recovery replay; a non-null array (even
     * empty) means a deliberate resume — the interrupted step consumes it.
     */
    protected ?array $pendingPayload = null;

    protected bool $pendingTimedOut = false;

    /**
     * @param array<string, mixed>|null $payload Null for start/replay; the delivered event payload on resume.
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    public function execute(WorkflowRuntimeInterface $workflow, ?array $payload = null, bool $timedOut = false): Generator
    {
        $this->persistence = $workflow->getPersistence();
        $this->scheduler = $workflow->getScheduler();
        $this->serializer = $workflow->getSerializer();
        $this->runId = $this->resolveIdentity($workflow, $payload);
        $workflow->adoptRunId($this->runId);
        $this->pendingPayload = $payload;
        $this->pendingTimedOut = $timedOut;

        $this->resolveIgnition($workflow, $payload);
        $workflow->bootstrap();

        $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowStart($workflow->getEventNodeMap()), $workflow);
        $workflow->getState()->set('__runId', $this->runId);

        try {
            // A deliberate resume cancels the wakeup it satisfies, leaving no
            // stale scheduler registration; start/replay fires no onResume.
            if ($payload !== null) {
                $this->scheduler->onResume($this->runId);
            }

            $terminal = yield from $this->traverse(
                $workflow,
                $workflow->getStartEvent(),
                $workflow->getState(),
            );

            if ($terminal instanceof InterruptEvent) {
                // A pause is a scheduled wait, not a failure: it is surfaced
                // functionally on the state and as a dedicated event, never an
                // AgentError. Steps are kept for resume.
                $workflow->getState()->markAsInterrupted($terminal->request);
                $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowInterrupted($terminal->request), $workflow);
                $this->scheduler->onSuspend($this->runId, $terminal->request);
                yield $terminal;
            } else {
                // Sweep the run's durable records — a completed run cannot be
                // woken. The correlation pointer is deliberately NOT touched:
                // it is a historical fact, and liveness is derived at lookup
                // from the ignition record this delete just removed.
                $this->persistence->delete($this->runId);
                $workflow->getState()->clearInterrupt();
                $this->scheduler->onComplete($this->runId);
            }

            return $workflow->getState();
        } catch (Throwable $e) {
            $this->dispatchEvent($workflow->getEventDispatcher(), new AgentError($e, false), $workflow);
            throw $e;
        } finally {
            $this->workflowEnd($workflow);
        }
    }

    /**
     * Establish WHICH run this segment belongs to: an explicit runId is
     * authoritative, a fresh start generates one, a continuation without an
     * id is resolved from the correlation pointer.
     *
     * @param array<string, mixed>|null $payload
     * @throws WorkflowException
     */
    protected function resolveIdentity(WorkflowRuntimeInterface $workflow, ?array $payload): string
    {
        $runId = $workflow->getRunId();

        if ($runId !== null) {
            return $runId;
        }

        if ($payload === null) {
            return uniqid('workflow_');
        }

        $correlationKey = $workflow->correlationKey();

        if ($correlationKey === null) {
            throw new WorkflowException(
                'Cannot address the run to continue: no runId was provided and the '
                . 'workflow declares no correlation key.'
            );
        }

        $runId = $this->persistence->get(self::CORRELATION_PARTITION, $correlationKey);

        // The pointer records the most recent run, not a run in flight:
        // liveness is derived from its ignition record still existing.
        if ($runId === null || $this->persistence->get($runId, self::IGNITION_KEY) === null) {
            throw new WorkflowException(
                "No run in flight for correlation key '{$correlationKey}' — nothing to continue."
            );
        }

        return $runId;
    }

    /**
     * Make the run self-describing before anything else happens: a fresh
     * ignition registers the trigger envelope; a wake of a run that was never
     * durably started fails loudly; an existing record is offered to the
     * workflow for adoption (a no-op when its local state is already set).
     *
     * @throws WorkflowException
     */
    protected function resolveIgnition(WorkflowRuntimeInterface $workflow, ?array $payload): void
    {
        $ignition = $this->loadIgnition();

        if (!$ignition instanceof Ignition && $payload === null) {
            $this->saveIgnition($workflow->makeIgnition());

            // The correlation pointer makes the run findable by its business key.
            $correlationKey = $workflow->correlationKey();
            if ($correlationKey !== null) {
                $this->persistence->put(self::CORRELATION_PARTITION, $correlationKey, $this->runId);
            }

            return;
        }

        if (!$ignition instanceof Ignition) {
            throw new WorkflowException(
                "Cannot wake run {$this->runId}: no ignition record — the run "
                . "was never durably started, or already completed."
            );
        }

        $workflow->adoptIgnition($ignition);
    }

    protected function saveIgnition(Ignition $ignition): void
    {
        $this->persistence->put($this->runId, self::IGNITION_KEY, $this->serializer->serialize($ignition));
    }

    protected function loadIgnition(): ?Ignition
    {
        $raw = $this->persistence->get($this->runId, self::IGNITION_KEY);
        $ignition = $raw === null ? null : $this->serializer->unserialize($raw);

        return $ignition instanceof Ignition ? $ignition : null;
    }

    protected function saveStep(string $stepId, StepResult $result): void
    {
        $this->persistence->put($this->runId, $stepId, $this->serializer->serialize($result));
    }

    protected function loadStep(string $stepId): ?StepResult
    {
        $raw = $this->persistence->get($this->runId, $stepId);
        $result = $raw === null ? null : $this->serializer->unserialize($raw);

        return $result instanceof StepResult ? $result : null;
    }

    protected function buildStepId(NodeInterface $node, ?string $branchId, int $index): string
    {
        return ($branchId !== null ? $branchId . '.' : '') . $node::class . '-' . $index;
    }

    /**
     * Run a single node through its full lifecycle, yielding streamed events
     * in real time. A thrown WorkflowInterrupt is converted here into an
     * InterruptEvent terminal — traversal never sees a thrown interrupt.
     *
     * @param WorkflowMiddleware[] $middleware
     * @return Generator<int, Event, mixed, Event>
     */
    protected function runNode(
        NodeInterface $node,
        NodeContext $context,
        array $middleware = [],
        ?string $branchId = null,
    ): Generator {
        $node->setWorkflowContext($context);

        $event = $context->event;
        $state = $context->state;
        $dispatcher = $context->dispatcher;

        $this->dispatchEvent($dispatcher, new WorkflowNodeStart($node::class, $state), $node, $branchId);

        try {
            foreach ($middleware as $m) {
                $this->dispatchEvent($dispatcher, new MiddlewareStart($m, $event, 'before'), $node, $branchId);
                $m->before($node, $event, $state);
                $this->dispatchEvent($dispatcher, new MiddlewareEnd($m, 'before'), $node, $branchId);
            }

            $result = $node->run($event, $state);

            if ($result instanceof Generator) {
                foreach ($result as $streamedEvent) {
                    yield $streamedEvent;
                }
                $result = $result->getReturn();
            }

            foreach ($middleware as $m) {
                $this->dispatchEvent($dispatcher, new MiddlewareStart($m, $result, 'after'), $node, $branchId);
                $m->after($node, $result, $state);
                $this->dispatchEvent($dispatcher, new MiddlewareEnd($m, 'after'), $node, $branchId);
            }

            $this->dispatchEvent($dispatcher, new WorkflowNodeEnd($node::class, $state), $node, $branchId);

            return $result;
        } catch (WorkflowInterrupt $interrupt) {
            // Events already yielded before the throw are preserved.
            return new InterruptEvent($interrupt->getRequest());
        }
    }

    /**
     * Run one node as a durable step: return the cached StepResult when a
     * prior run completed it (streamed events are not replayed), resume an
     * interrupted step by injecting the inbound payload, retry a failed one.
     *
     * @return Generator<int, Event, mixed, StepResult>
     * @throws Throwable
     */
    protected function runNodeStep(
        WorkflowRuntimeInterface $workflow,
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        ?string $branchId,
        string $stepId,
    ): Generator {
        $cached = $this->loadStep($stepId);

        // A recalled event was stripped of its transient capability at
        // persistence time; this deserialization site is where the workflow
        // restores it. Live results below never pass through restore.
        if ($cached instanceof StepResult && !$cached->isInterrupted() && !$cached->isFailed()) {
            return $cached->withEvent($workflow->restoreEvent($cached->getEvent()));
        }

        $resuming = $cached instanceof StepResult && $cached->isInterrupted() && $this->pendingPayload !== null;

        try {
            $terminal = yield from $this->runNode(
                $node,
                new NodeContext(
                    state: $state,
                    event: $event,
                    payload: $resuming ? $this->pendingPayload : null,
                    timedOut: $resuming && $this->pendingTimedOut,
                    memoizer: new StepMemoizer($this->persistence, $this->serializer, $this->runId, $stepId),
                    dispatcher: $workflow->getEventDispatcher(),
                ),
                $workflow->getMiddlewareForNode($node),
                $branchId,
            );
        } catch (Throwable $e) {
            // The failed marker makes this step retry on recovery,
            // never replay from cache.
            $this->saveStep($stepId, new StepResult(
                stepId: $stepId,
                error: ['message' => $e->getMessage(), 'class' => $e::class],
            ));
            throw $e;
        }

        // On interrupt, persist only a marker: the InterruptRequest rides the
        // event outbound but is NOT persisted — it is rebuilt by re-running
        // the node on resume, keeping developer objects out of the serializer.
        if ($terminal instanceof InterruptEvent) {
            $this->saveStep($stepId, new StepResult(stepId: $stepId, interrupted: true));
            return new StepResult(stepId: $stepId, event: $terminal, state: $state, interrupted: true);
        }

        $result = new StepResult(stepId: $stepId, event: $terminal, state: $state);
        $this->saveStep($stepId, $result);

        return $result;
    }

    /**
     * Traverse nodes as durable steps, on the main path ($branchId null) or
     * inside a parallel branch. Returns the terminal event: StopEvent on
     * completion, InterruptEvent when traversal paused for input.
     *
     * @return Generator<int, Event, mixed, Event>
     * @throws Throwable
     */
    protected function traverse(
        WorkflowRuntimeInterface $workflow,
        Event $event,
        WorkflowState $state,
        ?string $branchId = null,
    ): Generator {
        $node = $workflow->getNodeForEvent($event::class);
        $index = 0;

        while (!($event instanceof StopEvent) && !($event instanceof InterruptEvent)) {
            $stepId = $this->buildStepId($node, $branchId, $index++);

            $result = yield from $this->runNodeStep($workflow, $node, $event, $state, $branchId, $stepId);

            $event = $result->getEvent();

            if ($branchId === null) {
                // Main-path state follows the step result so a replayed step
                // restores its persisted state; a branch keeps its clone.
                $workflow->setState($result->getState());
                $state = $workflow->getState();
            }

            if ($event instanceof ParallelEvent) {
                $event = yield from $this->executeBranches($workflow, $event);
            }

            if ($event instanceof StopEvent || $event instanceof InterruptEvent) {
                break;
            }

            $node = $workflow->getNodeForEvent($event::class);
        }

        return $event;
    }

    /**
     * Execute parallel branches sequentially; a paused branch returns its
     * InterruptEvent instead of the ParallelEvent. Subclasses override to
     * change branch execution strategy.
     *
     * @return Generator<int, Event, mixed, ParallelEvent|InterruptEvent>
     * @throws Throwable
     */
    protected function executeBranches(
        WorkflowRuntimeInterface $workflow,
        ParallelEvent $parallelEvent,
    ): Generator {
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            $terminal = yield from $this->executeBranch($workflow, $branchId, $branchEvent);

            if ($terminal instanceof InterruptEvent) {
                return $terminal;
            }

            if ($terminal instanceof StopEvent) {
                $parallelEvent->setResult($branchId, $terminal->getResult());
            }
        }

        return $parallelEvent;
    }

    /**
     * Execute a single branch in isolation with a cloned state. Resume needs
     * no branch routing: the branch always re-runs from its start event and
     * step replay skips or resumes each step.
     *
     * @return Generator<int, Event, mixed, Event>
     * @throws Throwable
     */
    protected function executeBranch(
        WorkflowRuntimeInterface $workflow,
        string $branchId,
        Event $branchEvent,
    ): Generator {
        $branchState = clone $workflow->getState();
        $branchState->set('__branchId', $branchId);

        $this->dispatchEvent($workflow->getEventDispatcher(), new BranchStart($branchId), $workflow, $branchId);

        try {
            return yield from $this->traverse($workflow, $branchEvent, $branchState, $branchId);
        } finally {
            $this->dispatchEvent($workflow->getEventDispatcher(), new BranchEnd($branchId), $workflow, $branchId);
        }
    }

    protected function workflowEnd(WorkflowRuntimeInterface $workflow): void
    {
        $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowEnd($workflow->getState()), $workflow);
    }

    /**
     * Null dispatcher is a no-op so runNode() stays runnable
     * without a workflow (e.g. in tests).
     */
    protected function dispatchEvent(
        ?EventDispatcherInterface $dispatcher,
        ObservabilityEvent $event,
        object $source,
        ?string $branchId = null,
    ): void {
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $event->source = $source;
        $event->branchId = $branchId;
        $dispatcher->dispatch($event);
    }
}
