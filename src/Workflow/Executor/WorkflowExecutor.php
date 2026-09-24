<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Observability\ExecutionEventDispatcher;
use Generator;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\StaleWorkflowRunException;
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
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Events\BranchPausedEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\ResumeType;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowInspector;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowRuntimeInterface;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use DateTimeImmutable;

use function hash;
use function in_array;
use function time;

/**
 * Durable Workflow lifecycle and replay traversal. Every mutation is fenced
 * by the byte-identical __control value that granted this execution attempt.
 */
class WorkflowExecutor implements WorkflowExecutorInterface
{
    protected WorkflowRunStore $store;
    protected ?int $leaseTimeout = null;
    protected string $workflowId;
    protected string $runId;

    protected bool $pauseRequested = false;
    protected Ignition $ignition;

    public function inspect(Workflow $workflow): ?WorkflowRunSnapshot
    {
        $workflowId = $workflow->getWorkflowId();
        if ($workflowId === null) {
            return null;
        }
        return (new WorkflowInspector($workflow->getPersistence(), $workflow->getSerializer()))->inspect($workflowId);
    }

    /**
     * @template TWorkflow of Workflow
     * @param TWorkflow $workflow
     * @return Generator<int, object, mixed, WorkflowState>
     */
    public function execute(Workflow $workflow, ExecutionRequest $request): Generator
    {
        ExecutionGate::acquire($workflow);
        $runtime = null;
        $this->pauseRequested = false;
        try {
            $this->leaseTimeout = $workflow->getLeaseTimeout();
            $this->workflowId = $this->requireWorkflowId($workflow);
            $this->store = new WorkflowRunStore($workflow->getPersistence(), $workflow->getSerializer(), $this->workflowId);
            $terminal = $this->admit($workflow, $request);
            if ($terminal instanceof WorkflowState) {
                return $terminal;
            }
            $runtime = $this->openSegment($workflow);
            return yield from $this->executeOwnedSegment($runtime);
        } finally {
            if ($runtime !== null) {
                $this->workflowEnd($runtime);
            }
            ExecutionGate::release($workflow);
        }
    }

    /**
     * Build the admitted segment. The run is owned from admission on, so a
     * segment that cannot be built fails it.
     *
     * @throws Throwable
     */
    protected function openSegment(Workflow $workflow): WorkflowRuntimeInterface
    {
        $context = new ExecutionContext($this->workflowId, $this->runId, $this->store->control()->executionAttempt, $this->ignition);
        // Listeners registered while the segment is built observe the next one.
        $dispatcher = new ExecutionEventDispatcher($workflow->getEventDispatcher(), $context);
        try {
            return $workflow->createExecution($context);
        } catch (Throwable $e) {
            $state = new WorkflowState();
            $this->stampState($state);
            $state->markAsFailed();
            $this->markControlFailed();
            $this->dispatchEvent($dispatcher, new AgentError($e, false), $workflow);
            $this->dispatchEvent($dispatcher, new WorkflowEnd($state), $workflow);
            throw $e;
        }
    }

    /**
     * The segment settles its outcome before the output reports it: a failure
     * is persisted before any error frame, and the terminal frames follow the
     * committed suspension or completion.
     *
     * @return Generator<int, object, mixed, WorkflowState>
     */
    protected function executeOwnedSegment(WorkflowRuntimeInterface $workflow): Generator
    {
        $output = $workflow->getOutput();

        try {
            yield from $output->start();

            $this->dispatchEvent(
                $workflow->getEventDispatcher(),
                new WorkflowStart($workflow->getEventNodeMap()),
                $workflow,
            );

            $terminal = yield from $this->traverse(
                $workflow,
                $workflow->getStartEvent(),
                $workflow->getState(),
            );
            $state = $workflow->getState();
            $this->stampState($state);

            if ($terminal instanceof InterruptEvent || $terminal instanceof BranchPausedEvent) {
                $state->markAsSuspended($this->store->control()->interrupt->request);
                $checkpoint = clone $state;
                $checkpoint->markAsSuspended(null);
                $this->store->commitCheckpoint($checkpoint, $this->store->control()->suspended());

                $this->dispatchEvent(
                    $workflow->getEventDispatcher(),
                    new WorkflowInterrupted($state),
                    $workflow,
                );
            } else {
                $state->clearInterrupt();
                if ($workflow->shouldRetainCompletionUntilAcknowledged()) {
                    $this->store->commitOutcome($state, $this->store->control()->completed());
                } else {
                    $this->deleteOwnedPartition();
                }
            }
        } catch (Throwable $e) {
            $this->failSegment($workflow, $e);
            yield from $output->failed($e);
            throw $e;
        }

        yield from ($state->isInterrupted() ? $output->interrupted($state) : $output->completed($state));

        return clone $state;
    }

    protected function failSegment(WorkflowRuntimeInterface $workflow, Throwable $e): void
    {
        $this->stampState($workflow->getState());
        $workflow->getState()->markAsFailed();
        $this->markControlFailed();
        $this->dispatchEvent($workflow->getEventDispatcher(), new AgentError($e, false), $workflow);
    }

    protected function admit(Workflow $workflow, ExecutionRequest $request): ?WorkflowState
    {
        return $request->starting
            ? $this->startRun($workflow, $request)
            : $this->continueRun($workflow, $request);
    }

    /**
     * @throws StaleWorkflowRunException
     * @throws WorkflowException
     */
    public function acknowledgeCompletion(
        Workflow $workflow,
        string $expectedRunId,
    ): void {
        ExecutionGate::assertAvailable($workflow);
        $this->workflowId = $this->requireWorkflowId($workflow);
        $this->store = new WorkflowRunStore(
            $workflow->getPersistence(),
            $workflow->getSerializer(),
            $this->workflowId,
        );
        $this->loadControl($expectedRunId);

        $control = $this->store->control();
        if ($control->runId !== $expectedRunId) {
            throw new StaleWorkflowRunException($this->workflowId, $expectedRunId, $control->runId);
        }

        if ($control->status !== WorkflowStatus::Completed) {
            throw new WorkflowException(
                "Run '{$expectedRunId}' for workflow ID '{$this->workflowId}' is not completed."
            );
        }

        if (!$this->store->deleteIfOwned()) {
            throw new WorkflowException(
                "Completion acknowledgement conflicted for workflow ID '{$this->workflowId}'."
            );
        }
    }

    /**
     * Discard the generation holding the workflow ID and free its partition,
     * whatever it was waiting for. A retained completion is acknowledged
     * instead, and a run under a fresh lease is refused because a worker is
     * evidently executing it. The delete is fenced like every other mutation,
     * so a concurrent claim wins and is reported. False means nothing was in
     * flight.
     *
     * @throws StaleWorkflowRunException
     * @throws WorkflowException
     */
    public function abandonRun(Workflow $workflow, ?string $expectedRunId = null, ?int $expectedExecutionAttempt = null): bool
    {
        ExecutionGate::assertAvailable($workflow);
        $this->workflowId = $this->requireWorkflowId($workflow);
        $this->store = new WorkflowRunStore(
            $workflow->getPersistence(),
            $workflow->getSerializer(),
            $this->workflowId,
        );

        $control = $this->store->loadControl();
        if (!$control instanceof WorkflowControl) {
            if ($expectedRunId !== null) {
                throw new StaleWorkflowRunException($this->workflowId, $expectedRunId, null);
            }

            return false;
        }

        if ($expectedRunId !== null && $control->runId !== $expectedRunId) {
            throw new StaleWorkflowRunException($this->workflowId, $expectedRunId, $control->runId);
        }

        if ($expectedExecutionAttempt !== null && $control->executionAttempt !== $expectedExecutionAttempt) {
            throw new WorkflowException('Cannot abandon a different execution attempt.');
        }

        if ($control->status === WorkflowStatus::Completed) {
            throw new WorkflowException(
                "Run '{$control->runId}' for workflow ID '{$this->workflowId}' completed: "
                . 'release it with acknowledgeCompletion() instead of abandoning it.'
            );
        }

        if (
            $control->status === WorkflowStatus::Running
            && $control->leaseExpiresAt !== null
            && $control->leaseExpiresAt > time()
        ) {
            throw new WorkflowException(
                "Run '{$control->runId}' for workflow ID '{$this->workflowId}' appears to be executing "
                . "(lease expires at {$control->leaseExpiresAt}) and cannot be abandoned."
            );
        }

        if (!$this->store->deleteIfOwned()) {
            throw new WorkflowException(
                "Abandoning workflow ID '{$this->workflowId}' conflicted with a concurrent change; retry."
            );
        }

        return true;
    }

    /**
     * @throws WorkflowException
     */
    protected function startRun(Workflow $workflow, ExecutionRequest $request): ?WorkflowState
    {
        $reserved = $request->runId !== null;
        $this->runId = $request->runId ?? UniqueIdGenerator::generateId('run_');
        $control = new WorkflowControl(
            runId: $this->runId,
            status: WorkflowStatus::Running,
            leaseExpiresAt: $this->leaseExpiry(),
        );

        $ignition = $this->ignition = $workflow->makeIgnition($this->runId, $request->event() ?? $workflow->getStartEvent());

        $ignited = $this->store->initialize($control, $ignition);
        $current = $ignited ? null : $this->store->loadControl();

        if (!$reserved && $request->recoverFailed && $current?->status === WorkflowStatus::Failed) {
            // Fence the observed failure so recovery cannot target a generation
            // or attempt that another worker replaced while we were reading.
            return $this->continueRun($workflow, ExecutionRequest::resume(expectedRunId: $current->runId, expectedExecutionAttempt: $current->executionAttempt));
        }

        // A dead generation (failed, or lease expired) is swept and replaced; the
        // delete is fenced by the bytes just read, so a concurrent claimant wins.
        if (
            !$reserved && !$ignited
            && (!$current instanceof WorkflowControl || ($this->isDeadGeneration($current) && $this->store->deleteIfOwned()))
        ) {
            $ignited = $this->store->initialize($control, $ignition);
        }

        if (!$ignited) {
            throw $current instanceof WorkflowControl
                ? new RunInFlightException(
                    workflowId: $this->workflowId,
                    runId: $current->runId,
                    status: $current->status,
                    executionAttempt: $current->executionAttempt,
                    leaseExpiresAt: $current->leaseExpiresAt,
                    interrupt: $current->interrupt?->request,
                )
                : new WorkflowException(
                    "Cannot ignite a new run for workflow ID '{$this->workflowId}': "
                    . 'a concurrent process is changing it. Retry the ignition.'
                );
        }

        return null;
    }

    protected function isDeadGeneration(WorkflowControl $control): bool
    {
        return $control->status === WorkflowStatus::Failed
            || ($control->status === WorkflowStatus::Running
                && $control->leaseExpiresAt !== null
                && $control->leaseExpiresAt <= time());
    }

    protected function continueRun(Workflow $workflow, ExecutionRequest $request): ?WorkflowState
    {
        $payload = $request->payload();
        $expectedRunId = $request->runId;
        $expectedExecutionAttempt = $request->executionAttempt;
        $signalName = $request->signal;
        $this->loadControl($expectedRunId);
        $this->runId = $this->store->control()->runId;

        if ($expectedRunId !== null && $expectedRunId !== $this->runId) {
            throw new StaleWorkflowRunException($this->workflowId, $expectedRunId, $this->runId);
        }

        $control = $this->store->control();
        if (
            $expectedExecutionAttempt !== null
            && $expectedExecutionAttempt !== $control->executionAttempt
        ) {
            throw new WorkflowException(
                "Stale continuation for workflow ID '{$this->workflowId}': expected execution attempt "
                . "{$expectedExecutionAttempt}, current attempt is {$control->executionAttempt}."
            );
        }

        $ignition = $this->store->loadIgnition();
        if (!$ignition instanceof Ignition) {
            throw new WorkflowException(
                "Run '{$this->runId}' for workflow ID '{$this->workflowId}' has no ignition record."
            );
        }
        if ($ignition->runId !== $this->runId) {
            throw new WorkflowException(
                "Workflow ID '{$this->workflowId}' has mismatched __control and __ignition generations."
            );
        }

        $this->ignition = $ignition;

        if ($control->status === WorkflowStatus::Completed) {
            if ($signalName !== null) {
                throw new WorkflowException(
                    "No active interruption for workflow ID '{$this->workflowId}' is waiting for signal '{$signalName}'."
                );
            }
            $state = $this->store->loadOutcome();
            if (!$state instanceof WorkflowState) {
                throw new WorkflowException("Completed run '{$this->runId}' has no retained outcome.");
            }
            return $state;
        }

        $active = $control->interrupt;
        if ($signalName !== null) {
            if (!$active?->request instanceof WaitForEventRequest || $active->request->getEventName() !== $signalName) {
                throw new WorkflowException("The current interruption is not waiting for signal '{$signalName}'.");
            }
        }

        $input = null;
        if ($payload !== null) {
            if (!$active instanceof ActiveInterrupt) {
                throw new WorkflowException('There is no current interruption to answer.');
            }
            if (!in_array($control->status, [WorkflowStatus::Suspended, WorkflowStatus::Failed], true)) {
                throw new WorkflowException("Run '{$this->runId}' is '{$control->status->value}', not suspended.");
            }
            $input = ResumeInput::event($active->request, $payload);
        } else {
            $this->assertInputlessContinuationAllowed();
            $input = $this->dueInput();
        }
        if ($input instanceof ResumeInput) {
            $active->request->validate($input);
            $control = $control->withInput($input);
        }

        if ($control->interrupt instanceof ActiveInterrupt && !$control->interrupt->input instanceof ResumeInput) {
            $checkpoint = $this->store->loadCheckpoint();
            $state = $checkpoint instanceof WorkflowState ? $checkpoint : $workflow->newState();
            $settled = $control->status === WorkflowStatus::Suspended ? $control : $control->claim(null)->suspended();
            $state->setExecutionMetadata($this->workflowId, $this->runId, $settled->executionAttempt);
            $state->markAsSuspended($control->interrupt->request);
            $checkpoint = clone $state;
            $checkpoint->markAsSuspended(null);
            $this->store->commitCheckpoint($checkpoint, $settled);
            return $state;
        }

        $this->store->replaceControl($control->claim($this->leaseExpiry()));
        return null;
    }

    protected function dueInput(): ?ResumeInput
    {
        $active = $this->store->control()->interrupt;
        if (!$active instanceof ActiveInterrupt || $active->input instanceof ResumeInput) {
            return null;
        }
        $request = $active->request;
        if ($request instanceof SleepUntilRequest && $request->getWakeAt()->getTimestamp() <= time()) {
            return ResumeInput::timer($request);
        }
        if ($request instanceof WaitForEventRequest && $request->getExpiresAt() instanceof DateTimeImmutable
            && $request->getExpiresAt()->getTimestamp() <= time()) {
            return ResumeInput::expired($request);
        }
        return null;
    }

    /** @phpstan-impure The control record changes as branch generators advance. */
    protected function shouldPause(): bool
    {
        $active = $this->store->control()->interrupt;
        return $this->pauseRequested || ($active instanceof ActiveInterrupt && !$active->input instanceof ResumeInput);
    }

    protected function settleInterrupt(WorkflowControl $control): WorkflowControl
    {
        $nextStepId = $control->pendingSteps[0] ?? null;
        $next = $nextStepId === null ? null : $this->store->loadStep($nextStepId)->getEvent();
        return $control->removeInterrupt($next instanceof InterruptEvent
            ? new ActiveInterrupt($next->request, stepId: $nextStepId)
            : null);
    }

    protected function assertInputlessContinuationAllowed(): void
    {
        $control = $this->store->control();
        if (
            $control->status === WorkflowStatus::Running
            && $control->leaseExpiresAt !== null
            && $control->leaseExpiresAt > time()
        ) {
            throw new WorkflowException(
                "The run for workflow ID '{$this->workflowId}' appears to be executing "
                . "(lease expires at {$control->leaseExpiresAt}) — cannot continue without input."
            );
        }
    }

    /**
     * @throws WorkflowException
     */
    protected function requireWorkflowId(Workflow $workflow): string
    {
        return $workflow->getWorkflowId() ?? throw new WorkflowException(
            'Cannot identify the run: no workflow ID was provided '
            . 'and the workflow declares none.'
        );
    }

    /**
     * @throws StaleWorkflowRunException
     * @throws WorkflowException
     */
    protected function loadControl(?string $expectedRunId = null): void
    {
        if (!$this->store->loadControl() instanceof WorkflowControl) {
            if ($expectedRunId !== null) {
                throw new StaleWorkflowRunException($this->workflowId, $expectedRunId, null);
            }

            throw new WorkflowException(
                "No run in flight for workflow ID '{$this->workflowId}' — nothing to continue."
            );
        }
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
     * @throws WorkflowException
     */
    protected function deleteOwnedPartition(): void
    {
        if (!$this->store->deleteIfOwned()) {
            $attempt = $this->store->control()->executionAttempt;
            throw new WorkflowException(
                "Stale execution attempt {$attempt} cannot complete workflow ID '{$this->workflowId}'."
            );
        }
    }

    protected function leaseExpiry(): ?int
    {
        return $this->leaseTimeout === null ? null : time() + $this->leaseTimeout;
    }

    /**
     * @throws WorkflowException
     */
    protected function stampState(WorkflowState $state): void
    {
        $state->setExecutionMetadata(
            $this->workflowId,
            $this->runId,
            $this->store->control()->executionAttempt,
        );
    }

    protected function buildStepId(
        NodeInterface $node,
        ?string $branchId,
        ?string $branchPath,
        int $index,
    ): string {
        if ($branchId === null) {
            return $node::class . '-' . $index;
        }

        return 'branch_' . hash('sha256', $branchPath . "\0" . $node::class . "\0" . $index);
    }

    /**
     * @param WorkflowMiddleware[] $middleware
     * @return Generator<int, Event, mixed, Event>
     */
    protected function runNode(
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        NodeContext $context,
        WorkflowResources $resources,
        array $middleware = [],
        ?string $branchId = null,
    ): Generator {
        $node->setWorkflowContext($context);
        $dispatcher = $context->dispatcher;

        $this->dispatchEvent($dispatcher, new WorkflowNodeStart($node::class, $state), $node, $branchId);

        try {
            foreach ($middleware as $m) {
                $this->dispatchEvent($dispatcher, new MiddlewareStart($m, $event, 'before'), $node, $branchId);
                $m->before($node, $event, $state, $resources);
                $this->dispatchEvent($dispatcher, new MiddlewareEnd($m, 'before'), $node, $branchId);
            }

            $result = $node->run($event, $state, $resources);
            if ($result instanceof Generator) {
                foreach ($result as $streamedEvent) {
                    yield $streamedEvent;
                }
                $result = $result->getReturn();
            }

            foreach ($middleware as $m) {
                $this->dispatchEvent($dispatcher, new MiddlewareStart($m, $result, 'after'), $node, $branchId);
                $m->after($node, $result, $state, $resources);
                $this->dispatchEvent($dispatcher, new MiddlewareEnd($m, 'after'), $node, $branchId);
            }

            $this->dispatchEvent($dispatcher, new WorkflowNodeEnd($node::class, $state), $node, $branchId);
            return $result;
        } catch (WorkflowInterrupt $interrupt) {
            return InterruptEvent::fromRequest($interrupt->getRequest());
        }
    }

    /**
     * @return Generator<int, Event, mixed, StepResult>
     * @throws WorkflowException|Throwable
     */
    protected function runNodeStep(
        WorkflowRuntimeInterface $workflow,
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
        $payload = $input?->kind === ResumeType::Event ? $input->payload : null;
        $timedOut = $input?->kind === ResumeType::Expired;

        try {
            $execution = $this->runNode(
                $node,
                $event,
                $state,
                new NodeContext(
                    payload: $payload,
                    timedOut: $timedOut,
                    memoizer: $this->store->memoizer($stepId),
                    dispatcher: $workflow->getEventDispatcher(),
                    resuming: $resuming,
                    branchId: $branchId,
                ),
                $workflow->getResources(),
                $workflow->getMiddlewareForNode($node),
                $branchId,
            );
            // The output shapes what the node streams inside the step, so a
            // failing adapter fails the step like the node itself would.
            foreach ($execution as $item) {
                yield from $workflow->getOutput()->emit($item);
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
            $control = $control->heartbeat($this->leaseExpiry());
        }

        $this->store->commitStep($result, $control);

        return $result;
    }

    /** @return Generator<int, Event, mixed, Event> */
    protected function traverse(
        WorkflowRuntimeInterface $workflow,
        Event $event,
        WorkflowState $state,
        ?string $branchId = null,
        ?string $branchPath = null,
    ): Generator {
        $node = $workflow->getNodeForEvent($event::class);
        $index = 0;

        while (!($event instanceof StopEvent) && !($event instanceof InterruptEvent) && !($event instanceof BranchPausedEvent)) {
            $stepId = $this->buildStepId($node, $branchId, $branchPath, $index++);
            if ($this->shouldPause()) {
                return new BranchPausedEvent();
            }
            $result = yield from $this->runNodeStep($workflow, $node, $event, $state, $branchId, $stepId);
            $event = $result->getEvent();

            $state = $result->getState();
            if ($branchId === null) {
                $workflow->setState($state);
            }

            if ($event instanceof ParallelEvent) {
                if ($this->shouldPause()) {
                    return new BranchPausedEvent();
                }
                $event = yield from $this->executeBranches($workflow, $event, $stepId);
            }

            if ($event instanceof StopEvent || $event instanceof InterruptEvent || $event instanceof BranchPausedEvent) {
                break;
            }

            $node = $workflow->getNodeForEvent($event::class);
        }

        return $event;
    }

    /**
     * @return Generator<int, Event, mixed, ParallelEvent|BranchPausedEvent>
     */
    protected function executeBranches(
        WorkflowRuntimeInterface $workflow,
        ParallelEvent $parallelEvent,
        string $forkStepId,
    ): Generator {
        $paused = false;
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($this->shouldPause()) {
                return new BranchPausedEvent();
            }
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            $terminal = yield from $this->executeBranch($workflow, $branchId, $branchEvent, $forkStepId);
            if ($terminal instanceof StopEvent) {
                $parallelEvent->setResult($branchId, $terminal->getResult());
            } else {
                $paused = true;
            }
        }
        // Branches deferred while routing an accepted reply can now continue.
        if ($paused && !$this->shouldPause() && !$this->store->control()->interrupt instanceof ActiveInterrupt) {
            return yield from $this->executeBranches($workflow, $parallelEvent, $forkStepId);
        }

        return $paused || $this->shouldPause() ? new BranchPausedEvent() : $parallelEvent;
    }

    /**
     * @return Generator<int, Event, mixed, Event>
     */
    protected function executeBranch(
        WorkflowRuntimeInterface $workflow,
        string $branchId,
        Event $branchEvent,
        string $forkStepId,
    ): Generator {
        $branchState = clone $workflow->getState();
        $this->dispatchEvent($workflow->getEventDispatcher(), new BranchStart($branchId), $workflow, $branchId);

        try {
            return yield from $this->traverse(
                $workflow,
                $branchEvent,
                $branchState,
                $branchId,
                $forkStepId . "\0" . $branchId,
            );
        } finally {
            $this->dispatchEvent($workflow->getEventDispatcher(), new BranchEnd($branchId), $workflow, $branchId);
        }
    }

    protected function workflowEnd(WorkflowRuntimeInterface $workflow): void
    {
        $this->dispatchEvent($workflow->getEventDispatcher(), new WorkflowEnd($workflow->getState()), $workflow);
    }

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

        try {
            $dispatcher->dispatch($event);
        } catch (Throwable $e) {
            if ($event instanceof AgentError) {
                return;
            }

            $error = new AgentError($e, false);
            $error->source = $source;
            $error->branchId = $branchId;

            try {
                $dispatcher->dispatch($error);
            } catch (Throwable) {
                // Monitoring failures must not change Workflow execution.
            }
        }
    }
}
