<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
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
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Durable workflow executor with replay-based traversal.
 *
 * Traversal is driven by an injected StepEngineInterface, which owns persistence
 * and the replay/memoization logic. This executor depends on a step engine,
 * never on a persistence backend directly — Workflow constructs the engine from
 * the configured persistence and injects it.
 *
 * This is the in-process execution model. Alternative execution models (e.g. a
 * cloud-driven executor) implement WorkflowExecutorInterface directly.
 */
class WorkflowExecutor implements WorkflowExecutorInterface
{
    public function __construct(
        protected StepEngineInterface $stepEngine,
        protected SchedulerInterface $scheduler = new NullScheduler(),
    ) {
    }

    /**
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    public function execute(WorkflowInterface $workflow, ?array $payload = null, bool $timedOut = false): Generator
    {
        return yield from $this->doRun($workflow, $payload, $timedOut);
    }

    /**
     * Shared body for start/replay (no payload) and resume (a delivered payload).
     *
     * @param array<string, mixed>|null $payload Null for start/replay; the delivered event payload on resume.
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    private function doRun(
        WorkflowInterface $workflow,
        ?array $payload,
        bool $timedOut,
    ): Generator {
        $runId = $workflow->getRunId();
        $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowStart($workflow->getEventNodeMap()), $workflow);
        $workflow->resolveState()->set('__runId', $runId);

        $isResume = $payload !== null;

        try {
            $this->stepEngine->prepareExecution($runId, $payload, $timedOut);

            // A resume lets the scheduler cancel the wakeup it satisfies (inline or
            // scheduler push), so a deliberate resume leaves no stale registration.
            // A start/replay passes no payload and fires no onResume.
            if ($isResume) {
                $this->scheduler->onResume($runId);
            }

            /** @var Event $terminal */
            $terminal = yield from $this->traverse(
                $workflow,
                $workflow->getStartEvent(),
                $workflow->getNodeForEvent($workflow->getStartEvent()::class),
            );

            if ($terminal instanceof InterruptEvent) {
                // Paused: mark the state so callers of run()/events() can detect
                // the pause functionally. Steps are kept for resume.
                $workflow->resolveState()->markAsInterrupted($terminal->request);
                // Surface the pause to listeners — a scheduled wait, not a failure,
                // so it is a dedicated event rather than an AgentError.
                $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowInterrupted($terminal->request), $workflow);
                // Let the scheduler register a wakeup for this suspend (inert by default).
                $this->scheduler->onSuspend($runId, $terminal->request);
                yield $terminal;
            } else {
                // Completed: clean up persisted steps and clear any stale interrupt
                // marker (the status describes the outcome of this run only).
                $this->stepEngine->deleteSteps();
                $workflow->resolveState()->clearInterrupt();
                // Drop all scheduler coordination state for this workflow.
                $this->scheduler->onComplete($runId);
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
        Event $event,
        WorkflowState $state,
        array $middleware = [],
        ?string $branchId = null,
        ?array $payload = null,
        bool $timedOut = false,
        ?StepMemoizer $memoizer = null,
        ?EventDispatcherInterface $dispatcher = null,
    ): Generator {
        $node->setWorkflowContext($state, $event, $payload, $timedOut, $memoizer, $dispatcher);

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
     * Run one node as a durable step, appending its streamed events to $streamedEvents.
     *
     * Shared per-step body of traverse() and executeBranch(); the loops stay separate
     * (one is a live generator, the other buffers into a BranchResult), so only this
     * yield-free slice is unified.
     *
     * @param Event[] $streamedEvents
     */
    protected function runNodeStep(
        WorkflowInterface $workflow,
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        ?string $branchId,
        string $stepId,
        array &$streamedEvents,
    ): StepResult {
        return $this->stepEngine->runStep($stepId, function (?array $payload, bool $timedOut) use (
            $node,
            $event,
            $state,
            $workflow,
            $branchId,
            $stepId,
            &$streamedEvents,
        ): StepResult {
            $middleware = $workflow->getMiddlewareForNode($node);
            $nodeGen = $this->runNode(
                $node,
                $event,
                $state,
                $middleware,
                $branchId,
                $payload,
                $timedOut,
                new StepMemoizer($this->stepEngine, $stepId),
                $workflow->getEventDispatcher(),
            );
            foreach ($nodeGen as $streamedEvent) {
                $streamedEvents[] = $streamedEvent;
            }
            return new StepResult(
                stepId: $node::class,
                event: $nodeGen->getReturn(),
                state: $state,
            );
        });
    }

    /**
     * Traverse nodes sequentially from the given starting point.
     *
     * Returns the terminal event: a StopEvent on completion, or an InterruptEvent
     * when traversal paused for input.
     *
     * @return Generator<int, Event, mixed, Event>
     * @throws Throwable
     */
    protected function traverse(
        WorkflowInterface $workflow,
        Event $event,
        NodeInterface $node,
    ): Generator {
        $index = 0;

        while (!($event instanceof StopEvent) && !($event instanceof InterruptEvent)) {
            $stepId = $this->buildStepId($node, null, $index++);
            $streamedEvents = [];

            $result = $this->runNodeStep($workflow, $node, $event, $workflow->resolveState(), null, $stepId, $streamedEvents);

            foreach ($streamedEvents as $streamedEvent) {
                yield $streamedEvent;
            }

            $event = $result->getEvent();
            $workflow->setState($result->getState());

            if ($event instanceof ParallelEvent) {
                $branchGen = $this->executeBranches($workflow, $event);
                yield from $branchGen;
                $event = $branchGen->getReturn();
            }

            if ($event instanceof StopEvent || $event instanceof InterruptEvent) {
                break;
            }

            $node = $workflow->getNodeForEvent($event::class);
        }

        return $event;
    }

    /**
     * Execute parallel branches sequentially.
     *
     * If a branch pauses, returns its InterruptEvent as the terminal value
     * instead of the ParallelEvent. Subclasses override this to change branch
     * execution strategy (e.g. AsyncExecutor).
     *
     * @return Generator<int, Event, mixed, ParallelEvent|InterruptEvent>
     * @throws Throwable
     */
    protected function executeBranches(
        WorkflowInterface $workflow,
        ParallelEvent $parallelEvent,
    ): Generator {
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            $result = $this->executeBranch($workflow, $branchId, $branchEvent);

            if ($result->interrupt instanceof InterruptEvent) {
                return $result->interrupt;
            }

            $parallelEvent->setResult($branchId, $result->result);

            foreach ($result->streamedEvents as $streamedEvent) {
                yield $streamedEvent;
            }
        }

        return $parallelEvent;
    }

    /**
     * Execute a single branch in isolation with a cloned state.
     *
     * Resume of an interrupted branch is driven by step replay: the interrupted
     * step is cached and re-run with the pending resume request, so this method
     * needs no explicit resume/branch routing — it always starts from the
     * branch event and lets the step engine skip or resume per step.
     */
    protected function executeBranch(
        WorkflowInterface $workflow,
        string $branchId,
        Event $branchEvent,
    ): BranchResult {
        $streamedEvents = [];

        $branchState = clone $workflow->resolveState();
        $branchState->set('__branchId', $branchId);

        $this->dispatchEvent($workflow->getEventDispatcher(), new BranchStart($branchId), $workflow, $branchId);

        $node = $workflow->getNodeForEvent($branchEvent::class);
        $event = $branchEvent;
        $index = 0;

        try {
            while (!($event instanceof StopEvent) && !($event instanceof InterruptEvent)) {
                $stepId = $this->buildStepId($node, $branchId, $index++);

                $result = $this->runNodeStep($workflow, $node, $event, $branchState, $branchId, $stepId, $streamedEvents);

                $event = $result->getEvent();

                if ($event instanceof ParallelEvent) {
                    $branchGen = $this->executeBranches($workflow, $event);
                    foreach ($branchGen as $streamedEvent) {
                        $streamedEvents[] = $streamedEvent;
                    }
                    $event = $branchGen->getReturn();
                }

                if ($event instanceof StopEvent || $event instanceof InterruptEvent) {
                    break;
                }

                $node = $workflow->getNodeForEvent($event::class);
            }
        } finally {
            $this->dispatchEvent($workflow->getEventDispatcher(), new BranchEnd($branchId), $workflow, $branchId);
        }

        if ($event instanceof InterruptEvent) {
            return new BranchResult(
                branchId: $branchId,
                streamedEvents: $streamedEvents,
                interrupt: $event,
            );
        }

        return new BranchResult(
            branchId: $branchId,
            result: $event->getResult(),
            streamedEvents: $streamedEvents,
        );
    }

    protected function workflowEnd(WorkflowInterface $workflow): void
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
