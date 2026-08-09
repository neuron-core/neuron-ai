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
use NeuronAI\Workflow\WorkflowRuntimeInterface;
use NeuronAI\Workflow\WorkflowState;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * The run lifecycle, whole and unsplit: ignition (register / adopt / refuse),
 * bootstrap, replay-based traversal, and terminal handling.
 *
 * The executor owns no configuration — it reads the run's context (state
 * store, scheduler, run id, definition) from the WorkflowRuntimeInterface it
 * is handed, so one executor strategy composes with any persistence backend
 * or scheduler. Every executed node is persisted as a StepResult: on re-run,
 * previously completed steps are returned from cache without re-executing the
 * node; interrupted steps resume from the inbound payload; failed steps
 * (marked after an unhandled throwable) retry.
 *
 * Replay is keyed by step id alone: step ids are unique within a run
 * (monotonic traversal index, a branch prefix, a per-name memo suffix), so a
 * completed, non-interrupted, non-failed cached result is always a prior
 * run's work and is safe to skip. There is no generation counter and no scan
 * of stored steps.
 *
 * This is the in-process execution model. AsyncExecutor subclasses it to run
 * parallel branches concurrently; alternative execution models implement
 * WorkflowExecutorInterface directly.
 */
class WorkflowExecutor implements WorkflowExecutorInterface
{
    /**
     * Reserved id under which the ignition record rides the step store: the
     * envelope reuses the same persistence backend and serializer pipe as
     * every step, and is swept with them on clean completion. Node steps are
     * `NodeClass-index` and memo steps `stepId::name`, so the id can never
     * collide. A storage detail of this executor — the workflow sees only the
     * typed Ignition.
     */
    protected const IGNITION_STEP_ID = '__ignition';

    /**
     * The run context of the currently executing run, read off the workflow
     * runtime at the single entry point (execute) and held for the traversal
     * methods below.
     */
    protected PersistenceInterface $persistence;

    protected SchedulerInterface $scheduler;

    protected string $runId;

    /**
     * The inbound resume payload for this run. Null means a fresh start or a
     * crash-recovery replay (no resume); a non-null array (even empty) means
     * a deliberate resume — the interrupted step consumes it.
     */
    protected ?array $pendingPayload = null;

    protected bool $pendingTimedOut = false;

    /**
     * Execute the run: resolve ignition, bootstrap the definition, then
     * traverse nodes as durable steps.
     *
     * @param array<string, mixed>|null $payload Null for start/replay; the delivered event payload on resume.
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    public function execute(WorkflowRuntimeInterface $workflow, ?array $payload = null, bool $timedOut = false): Generator
    {
        $this->persistence = $workflow->getPersistence();
        $this->scheduler = $workflow->getScheduler();
        $this->runId = $workflow->getRunId();
        $this->pendingPayload = $payload;
        $this->pendingTimedOut = $timedOut;

        $this->resolveIgnition($workflow, $payload);
        $workflow->bootstrap();

        $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowStart($workflow->getEventNodeMap()), $workflow);
        $workflow->resolveState()->set('__runId', $this->runId);

        try {
            // A resume lets the scheduler cancel the wakeup it satisfies (inline or
            // scheduler push), so a deliberate resume leaves no stale registration.
            // A start/replay passes no payload and fires no onResume.
            if ($payload !== null) {
                $this->scheduler->onResume($this->runId);
            }

            $terminal = yield from $this->traverse(
                $workflow,
                $workflow->getStartEvent(),
                $workflow->resolveState(),
            );

            if ($terminal instanceof InterruptEvent) {
                // Paused: mark the state so callers of run()/events() can detect
                // the pause functionally. Steps are kept for resume.
                $workflow->resolveState()->markAsInterrupted($terminal->request);
                // Surface the pause to listeners — a scheduled wait, not a failure,
                // so it is a dedicated event rather than an AgentError.
                $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowInterrupted($terminal->request), $workflow);
                // Let the scheduler register a wakeup for this suspend (inert by default).
                $this->scheduler->onSuspend($this->runId, $terminal->request);
                yield $terminal;
            } else {
                // Completed: sweep the run's durable records (steps, memos, and the
                // ignition record — a completed run cannot be woken) and clear any
                // stale interrupt marker (the status describes this run only).
                $this->persistence->delete($this->runId);
                $workflow->resolveState()->clearInterrupt();
                // Drop all scheduler coordination state for this workflow.
                $this->scheduler->onComplete($this->runId);
            }

            return $workflow->resolveState();
        } catch (Throwable $e) {
            $this->dispatchEvent($workflow->getEventDispatcher(), new AgentError($e, false), $workflow);
            throw $e;
        } finally {
            $this->workflowEnd($workflow);
        }
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

        // Fresh ignition: register the run's trigger envelope.
        if ($ignition === null && $payload === null) {
            $this->saveIgnition($workflow->makeIgnition());
            return;
        }

        // A wake needs a run to wake.
        if ($ignition === null) {
            throw new WorkflowException(
                "Cannot wake run {$this->runId}: no ignition record — the run "
                . "was never durably started, or already completed."
            );
        }

        $workflow->adoptIgnition($ignition);
    }

    protected function saveIgnition(Ignition $ignition): void
    {
        $this->persistence->save($this->runId, self::IGNITION_STEP_ID, new StepResult(
            stepId: self::IGNITION_STEP_ID,
            output: $ignition,
        ));
    }

    protected function loadIgnition(): ?Ignition
    {
        $output = $this->persistence->load($this->runId, self::IGNITION_STEP_ID)?->getOutput();

        return $output instanceof Ignition ? $output : null;
    }

    /**
     * Build a unique step identifier for memoization.
     */
    protected function buildStepId(NodeInterface $node, ?string $branchId, int $index): string
    {
        return ($branchId !== null ? $branchId . '.' : '') . $node::class . '-' . $index;
    }

    /**
     * Run a single node through its full lifecycle.
     *
     * Yields streamed events from the node in real time; returns the final Event.
     * If the node or a pausing middleware throws WorkflowInterrupt, it is caught
     * here and converted into an InterruptEvent terminal — so traversal never sees
     * a thrown interrupt. Subclasses (e.g. AsyncExecutor) inherit this unchanged.
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
            // A pause request surfaced as a thrown signal from the node or a
            // middleware. Convert it to an InterruptEvent terminal. Events
            // already yielded before the throw are preserved.
            return new InterruptEvent($interrupt->getRequest());
        }
    }

    /**
     * Run one node as a durable step, memoized by step id, yielding its
     * streamed events through in real time and returning the StepResult.
     *
     * Returns the cached StepResult when a prior generation completed this
     * step (yielding nothing — streamed events are not replayed); resumes an
     * interrupted step by injecting the run's inbound payload; otherwise runs
     * the node and persists the outcome. Failed steps are never replayed from
     * cache — they must retry.
     *
     * @return Generator<int, Event, mixed, StepResult>
     */
    protected function runNodeStep(
        WorkflowRuntimeInterface $workflow,
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        ?string $branchId,
        string $stepId,
    ): Generator {
        $cached = $this->persistence->load($this->runId, $stepId);

        // Memoized: return a previously completed result without re-executing.
        if ($cached instanceof StepResult && !$cached->isInterrupted() && !$cached->isFailed()) {
            return $cached;
        }

        // Resuming an interrupted step injects the run's inbound payload.
        $resuming = $cached instanceof StepResult && $cached->isInterrupted() && $this->pendingPayload !== null;

        try {
            $terminal = yield from $this->runNode(
                $node,
                new NodeContext(
                    state: $state,
                    event: $event,
                    payload: $resuming ? $this->pendingPayload : null,
                    timedOut: $resuming && $this->pendingTimedOut,
                    memoizer: new StepMemoizer($this->persistence, $this->runId, $stepId),
                    dispatcher: $workflow->getEventDispatcher(),
                ),
                $workflow->getMiddlewareForNode($node),
                $branchId,
            );
        } catch (Throwable $e) {
            // Record a failed-step marker for crash observability, then rethrow.
            // On recovery the marker makes this step retry (never replayed from cache).
            $this->persistence->save($this->runId, $stepId, new StepResult(
                stepId: $stepId,
                error: ['message' => $e->getMessage(), 'class' => $e::class],
            ));
            throw $e;
        }

        // Interrupted: the step's terminal event is an InterruptEvent. Persist only an
        // interrupted marker (no throw) so the step resumes on the next run. The
        // InterruptRequest rides the event outbound (→ onSuspend / returned state) but
        // is NOT persisted — it is rebuilt by re-running the node on resume, which keeps
        // developer objects stuffed into a request out of the serializer.
        if ($terminal instanceof InterruptEvent) {
            $this->persistence->save($this->runId, $stepId, new StepResult(stepId: $stepId, interrupted: true));
            return new StepResult(stepId: $stepId, event: $terminal, state: $state);
        }

        // Persist the completed result.
        $result = new StepResult(stepId: $stepId, event: $terminal, state: $state);
        $this->persistence->save($this->runId, $stepId, $result);

        return $result;
    }

    /**
     * Traverse nodes as durable steps from the given starting event, on the main
     * path ($branchId null) or inside a parallel branch.
     *
     * Returns the terminal event: a StopEvent on completion, or an InterruptEvent
     * when traversal paused for input.
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

            // A recalled (cached) step returns a deserialized event whose
            // transient capability was stripped at persistence time; the
            // workflow restores it before the event re-enters traversal.
            // Idempotent — a no-op for live results.
            $event = $workflow->restoreEventNode($result->getEvent());

            if ($branchId === null) {
                // Main-path state follows the step result so a replayed (cached)
                // step restores its persisted state; a branch keeps its cloned
                // state for isolation.
                $workflow->setState($result->getState());
                $state = $workflow->resolveState();
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
     * Execute parallel branches sequentially, streaming each branch's events
     * through in real time.
     *
     * If a branch pauses, returns its InterruptEvent as the terminal value
     * instead of the ParallelEvent. Subclasses override this to change branch
     * execution strategy (e.g. AsyncExecutor).
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
     * Execute a single branch in isolation with a cloned state, yielding its
     * events and returning its terminal event (StopEvent or InterruptEvent).
     *
     * Resume of an interrupted branch is driven by step replay: the interrupted
     * step is cached and re-run with the pending resume request, so this method
     * needs no explicit resume/branch routing — it always starts from the
     * branch event and lets step replay skip or resume per step.
     *
     * @return Generator<int, Event, mixed, Event>
     * @throws Throwable
     */
    protected function executeBranch(
        WorkflowRuntimeInterface $workflow,
        string $branchId,
        Event $branchEvent,
    ): Generator {
        $branchState = clone $workflow->resolveState();
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
        $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowEnd($workflow->resolveState()), $workflow);
    }

    /**
     * Stamp the emission context on the event and dispatch it. Null dispatcher
     * is a no-op so runNode() stays runnable without a workflow (e.g. in tests).
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
