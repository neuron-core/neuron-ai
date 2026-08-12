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

use function str_starts_with;
use function time;
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
     * The generation head of the address partition: its presence is what "a
     * run in flight" means, and its record names the runId currently holding
     * the address. Deleted with the partition on clean completion.
     */
    protected const IGNITION_KEY = '__ignition';

    /**
     * The execution lease (opt-in): a unix-time heartbeat, or RELEASED on a
     * deliberate stop.
     */
    protected const LEASE_KEY = '__lease';

    protected const LEASE_RELEASED = 'released';

    protected PersistenceInterface $persistence;

    protected SchedulerInterface $scheduler;

    protected Serializer $serializer;

    protected ?int $leaseTimeout = null;

    protected string $address;

    protected string $runId;

    /**
     * Null means fresh start or crash-recovery replay; a non-null array (even
     * empty) means a deliberate resume — the interrupted step consumes it.
     */
    protected ?array $pendingPayload = null;

    protected bool $pendingTimedOut = false;

    /**
     * @param array<string, mixed>|null $payload The delivered answer on a continuation; null to deliver nothing.
     * @param bool $resuming True to continue the run at the address; false to ignite a new one.
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    public function execute(WorkflowRuntimeInterface $workflow, ?array $payload = null, bool $timedOut = false, bool $resuming = false): Generator
    {
        $this->persistence = $workflow->getPersistence();
        $this->scheduler = $workflow->getScheduler();
        $this->serializer = $workflow->getSerializer();
        $this->leaseTimeout = $workflow->getLeaseTimeout();
        $this->address = $this->resolveAddress($workflow, $resuming);
        $this->runId = $this->resolveIgnition($workflow, $resuming);
        $workflow->adoptIdentity($this->address, $this->runId);
        $this->pendingPayload = $payload;
        $this->pendingTimedOut = $timedOut;
        $this->renewLease();

        $workflow->bootstrap();

        $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowStart($workflow->getEventNodeMap()), $workflow);
        $workflow->getState()->set('__address', $this->address);
        $workflow->getState()->set('__runId', $this->runId);

        try {
            // A deliberate resume cancels the wakeup it satisfies, leaving no
            // stale scheduler registration; start/replay fires no onResume.
            if ($payload !== null) {
                $this->scheduler->onResume($this->address);
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
                $this->releaseLease();
                $this->scheduler->onSuspend($this->address, $this->runId, $terminal->request);
                yield $terminal;
            } else {
                // Fenced sweep: delete only while the generation head still
                // names this run. A stale replica completing after another
                // run took the address must not destroy the live run's
                // records or drop its coordination state. Check-then-delete
                // is not atomic — the residual window is accepted to keep
                // the store contract at three methods.
                $head = $this->loadIgnition();
                if ($head instanceof Ignition && $head->runId === $this->runId) {
                    $this->persistence->delete($this->address);
                    $this->scheduler->onComplete($this->address);
                }
                $workflow->getState()->clearInterrupt();
            }

            return $workflow->getState();
        } catch (Throwable $e) {
            try {
                $this->releaseLease();
            } catch (Throwable) {
                // The original failure is the story; an unreleased lease ages out.
            }

            $this->dispatchEvent($workflow->getEventDispatcher(), new AgentError($e, false), $workflow);
            throw $e;
        } finally {
            $this->workflowEnd($workflow);
        }
    }

    /**
     * Establish WHERE this workflow's durable records live. The declared
     * business key wins; an explicit address must agree with it — two
     * identities silently disagreeing is a mis-addressed run. A fresh
     * ignition without any address generates one.
     *
     * @throws WorkflowException
     */
    protected function resolveAddress(WorkflowRuntimeInterface $workflow, bool $resuming): string
    {
        // A later segment of an instance that already resolved identity keeps
        // it — local state wins. The declared/explicit conflict check below
        // guards caller statements, not identity adopted mid-run.
        if ($workflow->getRunId() !== null && $workflow->getAddress() !== null) {
            return $workflow->getAddress();
        }

        $declared = $workflow->address();
        $explicit = $workflow->getAddress();

        if ($declared !== null && $explicit !== null && $declared !== $explicit) {
            throw new WorkflowException(
                "Mis-addressed run: the workflow declares address '{$declared}' "
                . "but was given '{$explicit}'."
            );
        }

        $address = $declared ?? $explicit;

        if ($address === null) {
            if ($resuming) {
                throw new WorkflowException(
                    'Cannot address the run to continue: no address was provided '
                    . 'and the workflow declares none.'
                );
            }

            return uniqid('workflow_');
        }

        if ($address === '' || str_starts_with($address, '__')) {
            throw new WorkflowException(
                "Invalid address '{$address}': an address must be a non-empty "
                . "string and must not start with '__'."
            );
        }

        return $address;
    }

    /**
     * Resolve which run holds the address, returning its generation stamp.
     * An ignition refuses an address with a run in flight — a pending run is
     * settled by resuming it, never silently replaced. A continuation adopts
     * the record (a no-op for the workflow when its local state is already
     * set) and fails loudly when there is nothing to continue.
     *
     * @throws WorkflowException
     */
    protected function resolveIgnition(WorkflowRuntimeInterface $workflow, bool $resuming): string
    {
        $ignition = $this->loadIgnition();

        if ($resuming) {
            if (!$ignition instanceof Ignition) {
                throw new WorkflowException(
                    "No run in flight at address '{$this->address}' — nothing to continue."
                );
            }

            $this->guardLease();
            $workflow->adoptIgnition($ignition);

            return $ignition->runId;
        }

        if ($ignition instanceof Ignition) {
            throw new WorkflowException(
                "A run is already in flight at address '{$this->address}' — "
                . "resume or settle it before igniting a new one."
            );
        }

        $runId = uniqid('run_');
        $this->saveIgnition($workflow->makeIgnition($runId));

        return $runId;
    }

    protected function saveIgnition(Ignition $ignition): void
    {
        $this->persistence->put($this->address, self::IGNITION_KEY, $this->serializer->serialize($ignition));
    }

    protected function loadIgnition(): ?Ignition
    {
        $raw = $this->persistence->get($this->address, self::IGNITION_KEY);
        $ignition = $raw === null ? null : $this->serializer->unserialize($raw);

        return $ignition instanceof Ignition ? $ignition : null;
    }

    protected function saveStep(string $stepId, StepResult $result): void
    {
        $this->persistence->put($this->address, $this->recordKey($stepId), $this->serializer->serialize($result));
    }

    /**
     * Heartbeat the lease: "this run is still executing, as of now". A no-op
     * unless the workflow opted in via setLeaseTimeout().
     */
    protected function renewLease(): void
    {
        if ($this->leaseTimeout === null) {
            return;
        }

        $this->persistence->put($this->address, self::LEASE_KEY, (string) time());
    }

    /**
     * Mark the stop as deliberate: a released lease never blocks a resume,
     * distinguishing "paused/failed and said so" from the silence of a
     * violent crash.
     */
    protected function releaseLease(): void
    {
        if ($this->leaseTimeout === null) {
            return;
        }

        $this->persistence->put($this->address, self::LEASE_KEY, self::LEASE_RELEASED);
    }

    /**
     * Refuse to continue a run whose lease is held and fresher than the
     * timeout: a process is probably executing it right now, and adopting
     * it would duplicate live work, not revive dead work. The check is a
     * heartbeat-based guess, not mutual exclusion — a crashed run's lease
     * simply ages past the timeout and recovery proceeds.
     *
     * @throws WorkflowException
     */
    protected function guardLease(): void
    {
        if ($this->leaseTimeout === null) {
            return;
        }

        $lease = $this->persistence->get($this->address, self::LEASE_KEY);

        if ($lease === null || $lease === self::LEASE_RELEASED) {
            return;
        }

        $heartbeatAge = time() - (int) $lease;

        if ($heartbeatAge < $this->leaseTimeout) {
            throw new WorkflowException(
                "The run at address '{$this->address}' appears to be executing "
                . "(last heartbeat {$heartbeatAge}s ago, lease timeout {$this->leaseTimeout}s) — not resuming."
            );
        }
    }

    protected function loadStep(string $stepId): ?StepResult
    {
        $raw = $this->persistence->get($this->address, $this->recordKey($stepId));
        $result = $raw === null ? null : $this->serializer->unserialize($raw);

        return $result instanceof StepResult ? $result : null;
    }

    /**
     * Step and memo records are runId-prefixed: an address is reused across
     * runs, and a leftover record from a prior generation must never be
     * replayed as this run's work — step ids alone would collide.
     */
    protected function recordKey(string $stepId): string
    {
        return $this->runId . '/' . $stepId;
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
        // Liveness is signalled per step visit — replayed steps included: a
        // reviving process racing through its cached tail is executing too.
        $this->renewLease();

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
                    memoizer: new StepMemoizer($this->persistence, $this->serializer, $this->address, $this->recordKey($stepId)),
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
