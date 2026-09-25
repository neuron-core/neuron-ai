<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Events\BranchPausedEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\Graph;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\ResumeType;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Observability\BranchEnd;
use NeuronAI\Workflow\Observability\BranchStart;
use NeuronAI\Workflow\Observability\MiddlewareEnd;
use NeuronAI\Workflow\Observability\MiddlewareStart;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Observability\WorkflowInterrupted;
use NeuronAI\Workflow\Observability\WorkflowNodeEnd;
use NeuronAI\Workflow\Observability\WorkflowNodeStart;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\SegmentOutput;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function hash;
use function time;

/**
 * One admitted execution of a run. From admission until it settles, it owns
 * the run's execution attempt: it replays or runs every step from the run's
 * start event, commits each one fenced by that attempt, and settles the
 * outcome. Whatever fails inside it, building its graph and output included,
 * fails the run before the output reports it.
 */
final class Segment
{
    protected bool $pauseRequested = false;

    protected ExecutionEventDispatcher $events;

    protected BranchRunner $branches;

    protected Graph $graph;

    protected SegmentOutput $output;

    public function __construct(
        protected WorkflowRunStore $store,
        protected ExecutionContext $context,
        protected WorkflowState $state,
        protected ?int $leaseTimeout,
        protected bool $retainCompletion,
    ) {
        $this->state->markAsRunning();
        $this->stamp($this->state);
    }

    /**
     * Everything the definition decides is resolved before the segment
     * starts; the graph and the output are built inside it, after admission.
     *
     * @param Closure(Event): Graph $graph
     * @param Closure(): ?StreamAdapterInterface $adapter
     * @param Closure(): ?StreamingChannelInterface $channel
     * @return Generator<int, object, mixed, WorkflowState>
     * @throws Throwable
     * @internal
     */
    public function run(
        Closure $graph,
        Closure $adapter,
        Closure $channel,
        BranchRunner $branches,
        EventDispatcherInterface $dispatcher,
        object $source,
    ): Generator {
        $this->events = new ExecutionEventDispatcher($dispatcher, $this->context, $source);
        $this->branches = $branches;

        try {
            return yield from $this->execute($graph, $adapter, $channel);
        } finally {
            $this->report(new WorkflowEnd($this->state));
        }
    }

    /**
     * Run one branch of a fork, recording its result when it completes.
     *
     * @return Generator<int, object, mixed, bool> Whether the branch completed.
     * @throws Throwable
     */
    public function branch(ParallelEvent $fork, string $branchId, string $forkStepId): Generator
    {
        $this->report(new BranchStart($branchId), branchId: $branchId);

        try {
            $terminal = yield from $this->traverse(
                $fork->branches[$branchId],
                clone $this->state,
                $branchId,
                $forkStepId . "\0" . $branchId,
            );
        } finally {
            $this->report(new BranchEnd($branchId), branchId: $branchId);
        }

        if (!$terminal instanceof StopEvent) {
            return false;
        }

        $fork->setResult($branchId, $terminal->getResult());
        return true;
    }

    /** @phpstan-impure The control record changes as branch generators advance. */
    public function shouldPause(): bool
    {
        $active = $this->store->control()->interrupt;
        return $this->pauseRequested || ($active instanceof ActiveInterrupt && !$active->input instanceof ResumeInput);
    }

    /**
     * The segment settles its outcome before the output reports it: a failure
     * is persisted before any error frame, and the terminal frames follow the
     * committed suspension or completion.
     *
     * @param Closure(Event): Graph $graph
     * @param Closure(): ?StreamAdapterInterface $adapter
     * @param Closure(): ?StreamingChannelInterface $channel
     * @return Generator<int, object, mixed, WorkflowState>
     * @throws Throwable
     */
    protected function execute(Closure $graph, Closure $adapter, Closure $channel): Generator
    {
        $start = $this->context->startEvent();

        try {
            $this->graph = $graph($start);
            $this->output = new SegmentOutput($adapter(), $channel(), $this->events, $this->context->workflowId);

            yield from $this->output->start();
            $this->report(new WorkflowStart($this->graph->nodes()));

            $terminal = yield from $this->traverse($start, $this->state);
            $this->settle($terminal);
        } catch (Throwable $e) {
            $this->fail($e);
            // A segment that failed to build its output has no stream to end.
            if (isset($this->output)) {
                yield from $this->output->failed($e);
            }
            throw $e;
        }

        yield from ($this->state->isInterrupted() ? $this->output->interrupted($this->state) : $this->output->completed($this->state));

        return clone $this->state;
    }

    /**
     * @throws WorkflowException
     */
    protected function settle(Event $terminal): void
    {
        $this->stamp($this->state);

        if ($terminal instanceof InterruptEvent || $terminal instanceof BranchPausedEvent) {
            $this->state->markAsSuspended($this->store->control()->interrupt->request);
            $checkpoint = clone $this->state;
            $checkpoint->markAsSuspended(null);
            $this->store->commitCheckpoint($checkpoint, $this->store->control()->suspended());

            $this->report(new WorkflowInterrupted($this->state));
            return;
        }

        $this->state->clearInterrupt();
        if ($this->retainCompletion) {
            $this->store->commitOutcome($this->state, $this->store->control()->completed());
        } elseif (!$this->store->deleteIfOwned()) {
            throw new WorkflowException(
                "Stale execution attempt {$this->context->executionAttempt} cannot complete workflow ID '{$this->context->workflowId}'."
            );
        }
    }

    protected function fail(Throwable $e): void
    {
        $this->stamp($this->state);
        $this->state->markAsFailed();
        $this->markControlFailed();
        $this->report(new WorkflowError($e, false));
    }

    protected function markControlFailed(): void
    {
        if (!$this->store->hasControl() || $this->store->control()->status === WorkflowStatus::Completed) {
            return;
        }

        try {
            $this->store->replaceControl($this->store->control()->failed());
        } catch (Throwable) {
            // A newer owner won the control record. Preserve the original failure.
        }
    }

    /**
     * @return Generator<int, object, mixed, Event>
     * @throws Throwable
     */
    protected function traverse(
        Event $event,
        WorkflowState $state,
        ?string $branchId = null,
        ?string $branchPath = null,
    ): Generator {
        $node = $this->graph->nodeFor($event);
        $index = 0;

        while (!($event instanceof StopEvent) && !($event instanceof InterruptEvent) && !($event instanceof BranchPausedEvent)) {
            $stepId = $this->stepId($node, $branchId, $branchPath, $index++);
            if ($this->shouldPause()) {
                return new BranchPausedEvent();
            }
            $result = yield from $this->runNodeStep($node, $event, $state, $branchId, $stepId);
            $event = $result->getEvent();

            $state = $result->getState();
            if ($branchId === null) {
                $this->state = $state;
            }

            if ($event instanceof ParallelEvent) {
                if ($this->shouldPause()) {
                    return new BranchPausedEvent();
                }
                $event = yield from $this->fork($event, $stepId);
            }

            if ($event instanceof StopEvent || $event instanceof InterruptEvent || $event instanceof BranchPausedEvent) {
                break;
            }

            $node = $this->graph->nodeFor($event);
        }

        return $event;
    }

    /**
     * @return Generator<int, object, mixed, ParallelEvent|BranchPausedEvent>
     * @throws Throwable
     */
    protected function fork(ParallelEvent $fork, string $stepId): Generator
    {
        // Branches deferred while another routed an accepted reply run again
        // once no interruption is current.
        do {
            $paused = yield from $this->branches->run($this, $fork, $stepId);
        } while ($paused && !$this->pauseRequested && !$this->store->control()->interrupt instanceof ActiveInterrupt);

        return $paused || $this->shouldPause() ? new BranchPausedEvent() : $fork;
    }

    /**
     * @return Generator<int, object, mixed, StepResult>
     * @throws WorkflowException|Throwable
     */
    protected function runNodeStep(
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        ?string $branchId,
        string $stepId,
    ): Generator {
        $cached = $this->store->loadStep($stepId);

        if ($cached instanceof StepResult && !$cached->isInterrupted()) {
            return $cached;
        }

        $active = $this->store->control()->interrupt;
        $input = $cached?->getInterruptId() === $active?->request->getId() ? $active?->input : null;
        $resuming = $input instanceof ResumeInput;
        if ($cached?->isInterrupted() && !$resuming) {
            return $cached;
        }
        if ($active instanceof ActiveInterrupt && !$resuming) {
            return new StepResult($stepId, new BranchPausedEvent(), $state);
        }

        try {
            $execution = $this->runNode($node, $event, $state, new NodeContext(
                payload: $input?->kind === ResumeType::Event ? $input->payload : null,
                timedOut: $input?->kind === ResumeType::Expired,
                memoizer: $this->store->memoizer($stepId),
                dispatcher: $this->events,
                resuming: $resuming,
                branchId: $branchId,
            ));
            // The output shapes what the node streams inside the step, so a
            // failing adapter fails the step like the node itself would.
            foreach ($execution as $item) {
                yield from $this->output->emit($item);
            }
            $terminal = $execution->getReturn();
        } catch (Throwable $error) {
            $this->pauseRequested = true;
            throw $error;
        }

        $control = $this->store->control();
        if ($resuming) {
            $control = $this->settleInterrupt($control);
        }
        if ($terminal instanceof InterruptEvent) {
            $request = $terminal->request->withId($control->nextInterruptId);
            $terminal = InterruptEvent::fromRequest($request);
            $control = $control->addInterrupt(new ActiveInterrupt($request, stepId: $stepId));
            $this->pauseRequested = true;
            $marker = new StepResult(stepId: $stepId, event: $terminal, state: $state);
            $this->store->commitStep($marker, $control);
            return $marker;
        }

        $result = new StepResult(stepId: $stepId, event: $terminal, state: $state);

        if ($this->leaseTimeout !== null) {
            // The commit carries the lease renewal for the node that runs
            // next, so a heartbeat never costs a write of its own.
            $control = $control->heartbeat(time() + $this->leaseTimeout);
        }

        $this->store->commitStep($result, $control);

        return $result;
    }

    /**
     * @return Generator<int, object, mixed, Event>
     */
    protected function runNode(NodeInterface $node, Event $event, WorkflowState $state, NodeContext $context): Generator
    {
        $node->setWorkflowContext($context);
        $branchId = $context->branchId;
        $resources = $this->graph->resources;
        $middleware = $this->graph->middlewareFor($node);

        $this->report(new WorkflowNodeStart($node::class, $state), $node, $branchId);

        try {
            foreach ($middleware as $m) {
                $this->report(new MiddlewareStart($m, $event, 'before'), $node, $branchId);
                $m->before($node, $event, $state, $resources);
                $this->report(new MiddlewareEnd($m, 'before'), $node, $branchId);
            }

            $result = $node->run($event, $state, $resources);
            if ($result instanceof Generator) {
                foreach ($result as $item) {
                    yield $item;
                }
                $result = $result->getReturn();
            }

            foreach ($middleware as $m) {
                $this->report(new MiddlewareStart($m, $result, 'after'), $node, $branchId);
                $m->after($node, $result, $state, $resources);
                $this->report(new MiddlewareEnd($m, 'after'), $node, $branchId);
            }

            $this->report(new WorkflowNodeEnd($node::class, $state), $node, $branchId);
            return $result;
        } catch (WorkflowInterrupt $interrupt) {
            return InterruptEvent::fromRequest($interrupt->getRequest());
        }
    }

    protected function settleInterrupt(WorkflowControl $control): WorkflowControl
    {
        $nextStepId = $control->pendingSteps[0] ?? null;
        $next = $nextStepId === null ? null : $this->store->loadStep($nextStepId)->getEvent();
        return $control->removeInterrupt($next instanceof InterruptEvent
            ? new ActiveInterrupt($next->request, stepId: $nextStepId)
            : null);
    }

    protected function stepId(NodeInterface $node, ?string $branchId, ?string $branchPath, int $index): string
    {
        if ($branchId === null) {
            return $node::class . '-' . $index;
        }

        return 'branch_' . hash('sha256', $branchPath . "\0" . $node::class . "\0" . $index);
    }

    /**
     * A replayed step's state carries the identity of the attempt that
     * committed it, so the segment stamps its own again before settling.
     */
    protected function stamp(WorkflowState $state): void
    {
        $state->setExecutionMetadata(
            $this->context->workflowId,
            $this->context->runId,
            $this->context->executionAttempt,
        );
    }

    /**
     * Events without a source come from the workflow.
     */
    protected function report(ObservabilityEvent $event, ?NodeInterface $source = null, ?string $branchId = null): void
    {
        $event->source = $source;
        $event->branchId = $branchId;
        $this->events->report($event);
    }
}
