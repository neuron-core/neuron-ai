<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
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
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Resume\ResumeInput;
use NeuronAI\Workflow\Resume\ResumeInputResult;
use NeuronAI\Workflow\Resume\ResumeInputStatus;
use NeuronAI\Workflow\Resume\ResumeKind;
use NeuronAI\Workflow\Suspension\Suspension;
use NeuronAI\Workflow\WorkflowRuntimeInterface;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function in_array;
use function str_starts_with;
use function time;
use function uniqid;

/**
 * Durable Workflow lifecycle and replay traversal. Every mutation is fenced
 * by the byte-identical __control value that granted this execution attempt.
 */
class WorkflowExecutor implements WorkflowExecutorInterface
{
    protected WorkflowRunStore $runStore;
    protected ?int $leaseTimeout = null;
    protected string $workflowId;
    protected string $runId;

    /** @var array<int, ResumeInput> */
    protected array $pendingInputs = [];

    /** @var ResumeInputResult[] */
    protected array $inputResults = [];

    /**
     * @param list<ResumeInput> $inputs
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    public function execute(
        WorkflowRuntimeInterface $workflow,
        array $inputs = [],
        bool $resuming = false,
        ?string $expectedRunId = null,
        bool $recovering = false,
        ?int $expectedExecutionAttempt = null,
    ): Generator {
        $this->pendingInputs = [];
        $this->inputResults = [];
        $this->leaseTimeout = $workflow->getLeaseTimeout();
        $this->workflowId = $this->resolveWorkflowId($workflow, $resuming || $recovering);
        $this->runStore = new WorkflowRunStore(
            $workflow->getPersistence(),
            $workflow->getSerializer(),
            $this->workflowId,
        );

        $terminalState = $resuming || $recovering
            ? $this->continueRun($workflow, $inputs, $expectedRunId, $recovering, $expectedExecutionAttempt)
            : $this->startRun($workflow);

        if ($terminalState instanceof WorkflowState) {
            return $terminalState;
        }

        $workflow->adoptIdentity($this->workflowId, $this->runId);
        $workflow->bootstrap();

        $this->dispatchEvent(
            $workflow->getEventDispatcher(),
            new WorkflowStart($workflow->getEventNodeMap()),
            $workflow,
        );

        $workflow->getState()->markAsRunning();
        $this->stampState($workflow->getState());
        $workflow->getState()->setInputResults($this->inputResults);

        try {
            $terminal = yield from $this->traverse(
                $workflow,
                $workflow->getStartEvent(),
                $workflow->getState(),
            );
            $this->stampState($workflow->getState());

            if ($terminal instanceof InterruptEvent) {
                $workflow->getState()->setInputResults($this->inputResults);
                $requests = [];
                foreach ($terminal->all() as $interrupt) {
                    $requests[] = $interrupt->request;
                }

                $workflow->getState()->markAsSuspended($requests, $this->portableSuspensions());
                $this->runStore->replaceControl($this->runStore->control()->suspended());

                foreach ($requests as $request) {
                    $this->dispatchEvent(
                        $workflow->getEventDispatcher(),
                        new WorkflowInterrupted($request),
                        $workflow,
                    );
                }

                yield $terminal;
            } else {
                $workflow->getState()->setInputResults($this->inputResults);
                $workflow->getState()->clearInterrupt();
                if ($workflow->shouldRetainCompletionUntilAcknowledged()) {
                    $this->runStore->replaceControl(
                        $this->runStore->control()->completed($workflow->getState()),
                    );
                } else {
                    $this->deleteOwnedPartition();
                }
            }

            return $workflow->getState();
        } catch (Throwable $e) {
            $this->stampState($workflow->getState());
            $workflow->getState()->setInputResults($this->inputResults);
            $workflow->getState()->markAsFailed();
            $this->markControlFailed();
            $this->dispatchEvent($workflow->getEventDispatcher(), new AgentError($e, false), $workflow);
            throw $e;
        } finally {
            $this->workflowEnd($workflow);
        }
    }

    public function acknowledgeCompletion(
        WorkflowRuntimeInterface $workflow,
        string $expectedRunId,
    ): void {
        $this->workflowId = $this->resolveWorkflowId($workflow, true);
        $this->runStore = new WorkflowRunStore(
            $workflow->getPersistence(),
            $workflow->getSerializer(),
            $this->workflowId,
        );
        $this->loadControl($expectedRunId);

        $control = $this->runStore->control();
        if ($control->runId !== $expectedRunId) {
            throw new StaleWorkflowRunException($this->workflowId, $expectedRunId, $control->runId);
        }

        if ($control->status !== WorkflowStatus::Completed) {
            throw new WorkflowException(
                "Run '{$expectedRunId}' for workflow ID '{$this->workflowId}' is not completed."
            );
        }

        if (!$this->runStore->deleteIfOwned()) {
            throw new WorkflowException(
                "Completion acknowledgement conflicted for workflow ID '{$this->workflowId}'."
            );
        }
    }

    protected function startRun(WorkflowRuntimeInterface $workflow): ?WorkflowState
    {
        $this->runId = uniqid('run_');
        $control = new WorkflowControl(
            runId: $this->runId,
            status: WorkflowStatus::Running,
            leaseExpiresAt: $this->leaseExpiry(),
        );

        if (!$this->runStore->initialize($control, $workflow->makeIgnition($this->runId))) {
            throw new WorkflowException(
                "A run is already in flight for workflow ID '{$this->workflowId}' — "
                . 'resume or settle it before igniting a new one.'
            );
        }

        return null;
    }

    /** @param list<ResumeInput> $inputs */
    protected function continueRun(
        WorkflowRuntimeInterface $workflow,
        array $inputs,
        ?string $expectedRunId,
        bool $recovering,
        ?int $expectedExecutionAttempt,
    ): ?WorkflowState {
        $this->loadControl($expectedRunId);
        $this->runId = $this->runStore->control()->runId;

        if ($expectedRunId !== null && $expectedRunId !== $this->runId) {
            throw new StaleWorkflowRunException($this->workflowId, $expectedRunId, $this->runId);
        }

        $ignition = $this->runStore->loadIgnition();
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

        $workflow->adoptIdentity($this->workflowId, $this->runId);
        $workflow->adoptIgnition($ignition);

        if ($this->runStore->control()->status === WorkflowStatus::Completed) {
            $state = $this->runStore->control()->completedState;
            if (!$state instanceof WorkflowState) {
                throw new WorkflowException("Completed run '{$this->runId}' has no retained outcome.");
            }
            $workflow->setState($state);
            return $state;
        }

        if ($recovering) {
            $this->prepareRecovery($expectedExecutionAttempt);
        } else {
            $this->prepareResume($inputs);

            if ($this->pendingInputs === []) {
                $state = $workflow->getState();
                $this->stampState($state);
                $state->setInputResults($this->inputResults);
                $state->markAsSuspended([], $this->portableSuspensions());
                return $state;
            }
        }

        $this->runStore->replaceControl(
            $this->runStore->control()->withInputs($this->pendingInputs)->claim($this->leaseExpiry()),
        );

        return null;
    }

    /** @param list<ResumeInput> $inputs */
    protected function prepareResume(array $inputs): void
    {
        if ($inputs === []) {
            throw new WorkflowException('resume() requires at least one ResumeInput. Use recover() for replay.');
        }

        $control = $this->runStore->control();
        if (!in_array($control->status, [WorkflowStatus::Suspended, WorkflowStatus::Failed], true)) {
            throw new WorkflowException(
                "Run '{$this->runId}' is '{$control->status->value}', not suspended."
            );
        }

        $seen = [];
        foreach ($inputs as $input) {
            if (!$input instanceof ResumeInput) {
                throw new WorkflowException('Every resume input must be an instance of ' . ResumeInput::class . '.');
            }
            if (isset($seen[$input->suspensionId])) {
                throw new WorkflowException("Duplicate suspension ID {$input->suspensionId} in resume batch.");
            }
            $seen[$input->suspensionId] = true;

            $active = $control->suspensions[$input->suspensionId] ?? null;
            if (!$active instanceof ActiveSuspension) {
                $this->inputResults[] = new ResumeInputResult(
                    $input->suspensionId,
                    ResumeInputStatus::Stale,
                );
                continue;
            }

            $active->suspension->validate($input);
            $this->pendingInputs[$input->suspensionId] = $input;
            $this->inputResults[] = new ResumeInputResult(
                $input->suspensionId,
                ResumeInputStatus::Accepted,
            );
        }
    }

    protected function prepareRecovery(?int $expectedExecutionAttempt): void
    {
        $control = $this->runStore->control();
        if (
            $expectedExecutionAttempt !== null
            && $expectedExecutionAttempt !== $control->executionAttempt
        ) {
            throw new WorkflowException(
                "Stale recovery for workflow ID '{$this->workflowId}': expected execution attempt "
                . "{$expectedExecutionAttempt}, current attempt is {$control->executionAttempt}."
            );
        }

        if (
            $control->status === WorkflowStatus::Running
            && $this->leaseTimeout !== null
            && $control->leaseExpiresAt !== null
            && $control->leaseExpiresAt > time()
        ) {
            throw new WorkflowException(
                "The run for workflow ID '{$this->workflowId}' appears to be executing "
                . "(lease expires at {$control->leaseExpiresAt}) — not recovering."
            );
        }

        foreach ($control->suspensions as $id => $active) {
            if ($active->input instanceof ResumeInput) {
                $this->pendingInputs[$id] = $active->input;
            }
        }
    }

    protected function resolveWorkflowId(WorkflowRuntimeInterface $workflow, bool $continuing): string
    {
        if ($workflow->getWorkflowId() !== null && $workflow->getRunId() !== null) {
            return $workflow->getWorkflowId();
        }

        $declared = $workflow->workflowId();
        $explicit = $workflow->getWorkflowId();

        if ($declared !== null && $explicit !== null && $declared !== $explicit) {
            throw new WorkflowException(
                "Misidentified run: the workflow declares workflow ID '{$declared}' "
                . "but was given '{$explicit}'."
            );
        }

        $workflowId = $declared ?? $explicit;
        if ($workflowId === null) {
            if ($continuing) {
                throw new WorkflowException(
                    'Cannot identify the run to continue: no workflow ID was provided '
                    . 'and the workflow declares none.'
                );
            }
            return uniqid('workflow_');
        }

        if ($workflowId === '' || str_starts_with($workflowId, '__')) {
            throw new WorkflowException(
                "Invalid workflow ID '{$workflowId}': a workflow ID must be a non-empty "
                . "string and must not start with '__'."
            );
        }

        return $workflowId;
    }

    protected function loadControl(?string $expectedRunId = null): void
    {
        if (!$this->runStore->loadControl() instanceof WorkflowControl) {
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
        if (!$this->runStore->hasControl() || $this->runStore->control()->status === WorkflowStatus::Completed) {
            return;
        }

        try {
            $this->runStore->replaceControl($this->runStore->control()->failed());
        } catch (Throwable) {
            // A newer owner won the control record. Preserve the original failure.
        }
    }

    protected function deleteOwnedPartition(): void
    {
        if (!$this->runStore->deleteIfOwned()) {
            $attempt = $this->runStore->control()->executionAttempt;
            throw new WorkflowException(
                "Stale execution attempt {$attempt} cannot complete workflow ID '{$this->workflowId}'."
            );
        }
    }

    protected function leaseExpiry(): ?int
    {
        return $this->leaseTimeout === null ? null : time() + $this->leaseTimeout;
    }

    protected function renewLease(): void
    {
        if ($this->leaseTimeout !== null) {
            $this->runStore->replaceControl($this->runStore->control()->heartbeat($this->leaseExpiry()));
        }
    }

    protected function stampState(WorkflowState $state): void
    {
        $state->set('__workflowId', $this->workflowId);
        $state->set('__runId', $this->runId);
        $state->set('__executionAttempt', $this->runStore->control()->executionAttempt);
    }

    /** @return Suspension[] */
    protected function portableSuspensions(): array
    {
        $suspensions = [];
        foreach ($this->runStore->control()->suspensions as $active) {
            $suspensions[] = $active->suspension;
        }
        return $suspensions;
    }

    protected function loadStep(string $stepId): ?StepResult
    {
        $result = $this->runStore->readRecord($this->recordKey($stepId));

        return $result instanceof StepResult ? $result : null;
    }

    protected function recordKey(string $stepId): string
    {
        return $this->runId . '/' . $stepId;
    }

    protected function buildStepId(NodeInterface $node, ?string $branchId, int $index): string
    {
        return ($branchId !== null ? $branchId . '.' : '') . $node::class . '-' . $index;
    }

    /**
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
            return new InterruptEvent($interrupt->getRequest());
        }
    }

    /** @return Generator<int, Event, mixed, StepResult> */
    protected function runNodeStep(
        WorkflowRuntimeInterface $workflow,
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        ?string $branchId,
        string $stepId,
    ): Generator {
        $this->renewLease();
        $cached = $this->loadStep($stepId);

        if ($cached instanceof StepResult && !$cached->isInterrupted() && !$cached->isFailed()) {
            return $cached->withEvent($workflow->restoreEvent($cached->getEvent()));
        }

        $suspensionId = $cached?->getSuspensionId();
        $input = $suspensionId === null ? null : ($this->pendingInputs[$suspensionId] ?? null);
        $resuming = $input instanceof ResumeInput;
        $payload = $input?->kind === ResumeKind::Event ? $input->payload : null;
        $timedOut = $input?->kind === ResumeKind::Expired;

        try {
            $terminal = yield from $this->runNode(
                $node,
                new NodeContext(
                    state: $state,
                    event: $event,
                    payload: $payload,
                    timedOut: $timedOut,
                    resuming: $resuming,
                    memoizer: $this->runStore->memoizer($this->recordKey($stepId)),
                    dispatcher: $workflow->getEventDispatcher(),
                ),
                $workflow->getMiddlewareForNode($node),
                $branchId,
            );
        } catch (Throwable $e) {
            $this->runStore->writeRecords([
                $this->recordKey($stepId) => new StepResult(
                    stepId: $stepId,
                    interrupted: $suspensionId !== null,
                    suspensionId: $suspensionId,
                    error: ['message' => $e->getMessage(), 'class' => $e::class],
                ),
            ]);
            throw $e;
        }

        if ($terminal instanceof InterruptEvent) {
            if ($suspensionId !== null && !$resuming) {
                $active = $this->runStore->control()->suspensions[$suspensionId] ?? null;
                if (!$active instanceof ActiveSuspension) {
                    throw new WorkflowException("Suspension {$suspensionId} has no active control record.");
                }

                $interrupt = new InterruptEvent($terminal->request, $suspensionId);
                return new StepResult(
                    stepId: $stepId,
                    event: $interrupt,
                    state: $state,
                    interrupted: true,
                    suspensionId: $suspensionId,
                );
            }

            $newId = $this->runStore->control()->nextSuspensionId;
            $active = new ActiveSuspension(
                Suspension::fromRequest($newId, $terminal->request),
                $stepId,
                $branchId,
            );
            $control = $this->runStore->control();
            if ($suspensionId !== null) {
                $control = $control->removeSuspension($suspensionId);
                unset($this->pendingInputs[$suspensionId]);
            }
            $control = $control->addSuspension($active);

            $marker = new StepResult(
                stepId: $stepId,
                interrupted: true,
                suspensionId: $newId,
            );
            $this->runStore->replaceControl($control, [$this->recordKey($stepId) => $marker]);

            $interrupt = new InterruptEvent($terminal->request, $newId);
            return new StepResult(
                stepId: $stepId,
                event: $interrupt,
                state: $state,
                interrupted: true,
                suspensionId: $newId,
            );
        }

        $result = new StepResult(stepId: $stepId, event: $terminal, state: $state);
        $records = [$this->recordKey($stepId) => $result];

        if ($suspensionId !== null) {
            unset($this->pendingInputs[$suspensionId]);
            $this->runStore->replaceControl(
                $this->runStore->control()->removeSuspension($suspensionId),
                $records,
            );
        } else {
            $this->runStore->writeRecords($records);
        }

        return $result;
    }

    /** @return Generator<int, Event, mixed, Event> */
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

    /** @return Generator<int, Event, mixed, ParallelEvent|InterruptEvent> */
    protected function executeBranches(
        WorkflowRuntimeInterface $workflow,
        ParallelEvent $parallelEvent,
    ): Generator {
        $interrupts = [];

        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            $terminal = yield from $this->executeBranch($workflow, $branchId, $branchEvent);
            if ($terminal instanceof InterruptEvent) {
                foreach ($terminal->all() as $interrupt) {
                    $interrupts[] = $interrupt;
                }
            } elseif ($terminal instanceof StopEvent) {
                $parallelEvent->setResult($branchId, $terminal->getResult());
            }
        }

        return $interrupts === [] ? $parallelEvent : InterruptEvent::aggregate($interrupts);
    }

    /** @return Generator<int, Event, mixed, Event> */
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
