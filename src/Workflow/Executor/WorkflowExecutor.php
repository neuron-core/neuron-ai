<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

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
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\ResumeInputResult;
use NeuronAI\Workflow\Interrupt\ResumeInputStatus;
use NeuronAI\Workflow\Interrupt\ResumeKind;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowRuntimeInterface;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use DateTimeImmutable;

use function array_push;
use function hash;
use function in_array;
use function str_starts_with;
use function time;

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
    protected bool $ownsExecutionSegment = false;

    /** @var ResumeInputResult[] */
    protected array $inputResults = [];

    /**
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    public function execute(WorkflowRuntimeInterface $workflow): Generator
    {
        return $this->executeSegment($workflow);
    }

    /**
     * @param list<ResumeInput> $inputs
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    public function resume(
        WorkflowRuntimeInterface $workflow,
        array $inputs = [],
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): Generator {
        return $this->executeSegment(
            $workflow,
            $inputs,
            continuing: true,
            expectedRunId: $expectedRunId,
            expectedExecutionAttempt: $expectedExecutionAttempt,
        );
    }

    /**
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    public function signal(
        WorkflowRuntimeInterface $workflow,
        string $name,
        array $payload = [],
    ): Generator {
        return $this->executeSegment(
            $workflow,
            continuing: true,
            signalName: $name,
            signalPayload: $payload,
        );
    }

    /**
     * @param list<ResumeInput> $inputs
     * @return Generator<int, Event, mixed, WorkflowState>
     * @throws Throwable
     */
    protected function executeSegment(
        WorkflowRuntimeInterface $workflow,
        array $inputs = [],
        bool $continuing = false,
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
        ?string $signalName = null,
        array $signalPayload = [],
    ): Generator {
        $this->inputResults = [];
        $this->ownsExecutionSegment = false;
        $this->leaseTimeout = $workflow->getLeaseTimeout();
        $this->workflowId = $this->resolveWorkflowId($workflow, $continuing);
        $this->runStore = new WorkflowRunStore(
            $workflow->getPersistence(),
            $workflow->getSerializer(),
            $this->workflowId,
        );

        try {
            $terminalState = $continuing
                ? $this->continueRun(
                    $workflow,
                    $inputs,
                    $expectedRunId,
                    $expectedExecutionAttempt,
                    $signalName,
                    $signalPayload,
                )
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

            $terminal = yield from $this->traverse(
                $workflow,
                $workflow->getStartEvent(),
                $workflow->getState(),
            );
            $this->stampState($workflow->getState());

            if ($terminal instanceof InterruptEvent) {
                $workflow->getState()->setInputResults($this->inputResults);
                $requests = $this->runStore->control()->interruptRequests();
                $workflow->getState()->markAsSuspended($requests);
                $checkpoint = clone $workflow->getState();
                $checkpoint->markAsSuspended([]);
                $this->runStore->replaceControl($this->runStore->control()->suspended($checkpoint));

                $this->dispatchEvent(
                    $workflow->getEventDispatcher(),
                    new WorkflowInterrupted($workflow->getState()),
                    $workflow,
                );

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
            if ($this->ownsExecutionSegment) {
                $this->stampState($workflow->getState());
                $workflow->getState()->setInputResults($this->inputResults);
                $workflow->getState()->markAsFailed();
                $this->markControlFailed();
                $this->dispatchEvent($workflow->getEventDispatcher(), new AgentError($e, false), $workflow);
            }
            throw $e;
        } finally {
            if ($this->ownsExecutionSegment) {
                $this->workflowEnd($workflow);
            }
        }
    }

    /**
     * @throws StaleWorkflowRunException
     * @throws WorkflowException
     */
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

    /**
     * @throws WorkflowException
     */
    protected function startRun(WorkflowRuntimeInterface $workflow): ?WorkflowState
    {
        $this->runId = UniqueIdGenerator::generateId('run_');
        $control = new WorkflowControl(
            runId: $this->runId,
            status: WorkflowStatus::Running,
            leaseExpiresAt: $this->leaseExpiry(),
        );

        $ignition = $workflow->makeIgnition($this->runId);

        $ignited = $this->runStore->initialize($control, $ignition);
        $current = $ignited ? null : $this->runStore->loadControl();

        // A dead generation (failed, or lease expired) is swept and replaced; the
        // delete is fenced by the bytes just read, so a concurrent claimant wins.
        if (
            !$ignited
            && ($current === null || ($this->isDeadGeneration($current) && $this->runStore->deleteIfOwned()))
        ) {
            $ignited = $this->runStore->initialize($control, $ignition);
        }

        if (!$ignited) {
            throw $current instanceof WorkflowControl
                ? new RunInFlightException(
                    workflowId: $this->workflowId,
                    runId: $current->runId,
                    status: $current->status,
                    executionAttempt: $current->executionAttempt,
                    leaseExpiresAt: $current->leaseExpiresAt,
                    interrupts: $current->interruptRequests(),
                )
                : new WorkflowException(
                    "Cannot ignite a new run for workflow ID '{$this->workflowId}': "
                    . 'a concurrent process is changing it. Retry the ignition.'
                );
        }

        $this->ownsExecutionSegment = true;

        return null;
    }

    protected function isDeadGeneration(WorkflowControl $control): bool
    {
        return $control->status === WorkflowStatus::Failed
            || ($control->status === WorkflowStatus::Running
                && $control->leaseExpiresAt !== null
                && $control->leaseExpiresAt <= time());
    }

    /**
     * @param list<ResumeInput> $inputs
     * @throws WorkflowException
     */
    protected function continueRun(
        WorkflowRuntimeInterface $workflow,
        array $inputs,
        ?string $expectedRunId,
        ?int $expectedExecutionAttempt,
        ?string $signalName,
        array $signalPayload,
    ): ?WorkflowState {
        $this->loadControl($expectedRunId);
        $this->runId = $this->runStore->control()->runId;

        if ($expectedRunId !== null && $expectedRunId !== $this->runId) {
            throw new StaleWorkflowRunException($this->workflowId, $expectedRunId, $this->runId);
        }

        $control = $this->runStore->control();
        if (
            $expectedExecutionAttempt !== null
            && $expectedExecutionAttempt !== $control->executionAttempt
        ) {
            throw new WorkflowException(
                "Stale continuation for workflow ID '{$this->workflowId}': expected execution attempt "
                . "{$expectedExecutionAttempt}, current attempt is {$control->executionAttempt}."
            );
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

        if ($control->status === WorkflowStatus::Completed) {
            if ($signalName !== null) {
                throw new WorkflowException(
                    "No active interruption for workflow ID '{$this->workflowId}' is waiting for signal '{$signalName}'."
                );
            }
            $state = $control->completedState;
            if (!$state instanceof WorkflowState) {
                throw new WorkflowException("Completed run '{$this->runId}' has no retained outcome.");
            }
            $workflow->setState($state);
            return $state;
        }

        $acceptedInputs = [];
        if ($signalName !== null) {
            $acceptedInputs = $this->prepareSignal($signalName, $signalPayload);
        } elseif ($inputs === []) {
            $this->assertInputlessContinuationAllowed();
            $dueInputs = $this->dueInputs();
            $acceptedInputs = $dueInputs === []
                ? []
                : $this->prepareResume($dueInputs, allowRunning: true);
        } else {
            $acceptedInputs = $this->prepareResume($inputs);

            if ($acceptedInputs === []) {
                $control = $this->runStore->control();
                $state = $control->checkpointState instanceof WorkflowState
                    ? clone $control->checkpointState
                    : $workflow->getState();
                $workflow->setState($state);
                $this->stampState($state);
                $state->setInputResults($this->inputResults);
                if ($control->status === WorkflowStatus::Failed) {
                    $state->markAsFailed();
                } else {
                    $state->markAsSuspended($this->runStore->control()->interruptRequests());
                }
                return $state;
            }
        }

        $this->runStore->replaceControl(
            (
                $acceptedInputs === []
                ? $this->runStore->control()
                : $this->runStore->control()->withInputs($acceptedInputs)
            )->claim($this->leaseExpiry()),
        );
        $this->ownsExecutionSegment = true;

        return null;
    }

    /**
     * @param non-empty-list<ResumeInput> $inputs
     * @return array<int, ResumeInput>
     * @throws WorkflowException
     */
    protected function prepareResume(array $inputs, bool $allowRunning = false): array
    {
        $control = $this->runStore->control();
        $canResume = in_array($control->status, [WorkflowStatus::Suspended, WorkflowStatus::Failed], true)
            || ($allowRunning && $control->status === WorkflowStatus::Running);

        if (!$canResume) {
            throw new WorkflowException(
                "Run '{$this->runId}' is '{$control->status->value}', not suspended."
            );
        }

        $seen = [];
        $accepted = [];
        foreach ($inputs as $input) {
            if (!$input instanceof ResumeInput) {
                throw new WorkflowException('Every resume input must be an instance of ' . ResumeInput::class . '.');
            }
            if (isset($seen[$input->interruptId])) {
                throw new WorkflowException("Duplicate interrupt ID {$input->interruptId} in resume batch.");
            }
            $seen[$input->interruptId] = true;

            $active = $control->interrupts[$input->interruptId] ?? null;
            if (!$active instanceof ActiveInterrupt) {
                $this->inputResults[] = new ResumeInputResult(
                    $input->interruptId,
                    ResumeInputStatus::Stale,
                );
                continue;
            }

            $active->request->validate($input);
            $accepted[$input->interruptId] = $input;
            $this->inputResults[] = new ResumeInputResult(
                $input->interruptId,
                ResumeInputStatus::Accepted,
            );
        }

        return $accepted;
    }

    /**
     * @return array<int, ResumeInput>
     * @throws WorkflowException
     */
    protected function prepareSignal(string $name, array $payload): array
    {
        $inputs = [];
        foreach ($this->runStore->control()->interrupts as $active) {
            $request = $active->request;
            if (
                $request instanceof WaitForEventRequest
                && $request->getEventName() === $name
            ) {
                $inputs[] = ResumeInput::event($request, $payload);
            }
        }

        if ($inputs === []) {
            throw new WorkflowException(
                "No active interruption for workflow ID '{$this->workflowId}' is waiting for signal '{$name}'."
            );
        }

        return $this->prepareResume($inputs);
    }

    /**
     * @return list<ResumeInput>
     * @throws WorkflowException
     */
    protected function dueInputs(): array
    {
        $now = time();
        $inputs = [];

        foreach ($this->runStore->control()->interrupts as $active) {
            if ($active->input instanceof ResumeInput) {
                continue;
            }

            $request = $active->request;
            if ($request instanceof SleepUntilRequest && $request->getWakeAt()->getTimestamp() <= $now) {
                $inputs[] = ResumeInput::timer($request);
                continue;
            }

            $expiresAt = $request instanceof WaitForEventRequest
                ? $request->getExpiresAt()
                : null;
            if ($expiresAt instanceof DateTimeImmutable && $expiresAt->getTimestamp() <= $now) {
                $inputs[] = ResumeInput::expired($request);
            }
        }

        return $inputs;
    }

    /**
     * @throws WorkflowException
     */
    protected function assertInputlessContinuationAllowed(): void
    {
        $control = $this->runStore->control();
        if (
            $control->status === WorkflowStatus::Running
            && $this->leaseTimeout !== null
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
            return UniqueIdGenerator::generateId('workflow_');
        }

        if ($workflowId === '' || str_starts_with($workflowId, '__')) {
            throw new WorkflowException(
                "Invalid workflow ID '{$workflowId}': a workflow ID must be a non-empty "
                . "string and must not start with '__'."
            );
        }

        return $workflowId;
    }

    /**
     * @throws StaleWorkflowRunException
     * @throws WorkflowException
     */
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

    /**
     * @throws WorkflowException
     */
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

    /**
     * @throws WorkflowException
     */
    protected function renewLease(): void
    {
        if ($this->leaseTimeout !== null) {
            $this->runStore->replaceControl($this->runStore->control()->heartbeat($this->leaseExpiry()));
        }
    }

    /**
     * @throws WorkflowException
     */
    protected function stampState(WorkflowState $state): void
    {
        $state->setExecutionMetadata(
            $this->workflowId,
            $this->runId,
            $this->runStore->control()->executionAttempt,
        );
    }

    protected function recordKey(string $stepId): string
    {
        return $this->runId . '/' . $stepId;
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
        $this->renewLease();
        $cached = $this->runStore->loadStep($this->recordKey($stepId));

        if ($cached instanceof StepResult && !$cached->isInterrupted() && !$cached->isFailed()) {
            return $cached->withEvent($workflow->restoreEvent($cached->getEvent()));
        }

        $interruptId = $cached?->getInterruptId();
        $active = $interruptId === null
            ? null
            : ($this->runStore->control()->interrupts[$interruptId] ?? null);
        if ($interruptId !== null && !$active instanceof ActiveInterrupt) {
            throw new WorkflowException("Interrupt {$interruptId} has no active control record.");
        }

        $input = $active?->input;
        $resuming = $input instanceof ResumeInput;
        $payload = $input?->kind === ResumeKind::Event ? $input->payload : null;
        $timedOut = $input?->kind === ResumeKind::Expired;

        if ($active instanceof ActiveInterrupt && !$resuming) {
            return new StepResult(
                stepId: $stepId,
                event: InterruptEvent::fromRequest($active->request),
                state: $cached->getState(),
                interruptId: $interruptId,
            );
        }

        try {
            $terminal = yield from $this->runNode(
                $node,
                new NodeContext(
                    state: $state,
                    event: $event,
                    payload: $payload,
                    timedOut: $timedOut,
                    memoizer: $this->runStore->memoizer($this->recordKey($stepId)),
                    dispatcher: $workflow->getEventDispatcher(),
                    resuming: $resuming,
                ),
                $workflow->getMiddlewareForNode($node),
                $branchId,
            );
        } catch (Throwable $e) {
            $this->runStore->writeRecords([
                $this->recordKey($stepId) => new StepResult(
                    stepId: $stepId,
                    interruptId: $interruptId,
                    failed: true,
                ),
            ]);
            throw $e;
        }

        if ($terminal instanceof InterruptEvent) {
            $request = $terminal->requests[0]->withId($this->runStore->control()->nextInterruptId);
            $newId = $request->getId();
            $active = new ActiveInterrupt($request);
            $control = $this->runStore->control();
            if ($interruptId !== null) {
                $control = $control->removeInterrupt($interruptId);
            }
            $control = $control->addInterrupt($active);

            $marker = new StepResult(
                stepId: $stepId,
                state: $state,
                interruptId: $newId,
            );
            $this->runStore->replaceControl($control, [$this->recordKey($stepId) => $marker]);

            return new StepResult(
                stepId: $stepId,
                event: InterruptEvent::fromRequest($request),
                state: $state,
                interruptId: $newId,
            );
        }

        $result = new StepResult(stepId: $stepId, event: $terminal, state: $state);
        $records = [$this->recordKey($stepId) => $result];

        if ($interruptId !== null) {
            $this->runStore->replaceControl(
                $this->runStore->control()->removeInterrupt($interruptId),
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
        ?string $branchPath = null,
    ): Generator {
        $node = $workflow->getNodeForEvent($event::class);
        $index = 0;

        while (!($event instanceof StopEvent) && !($event instanceof InterruptEvent)) {
            $stepId = $this->buildStepId($node, $branchId, $branchPath, $index++);
            $result = yield from $this->runNodeStep($workflow, $node, $event, $state, $branchId, $stepId);
            $event = $result->getEvent();

            if ($branchId === null) {
                $workflow->setState($result->getState());
                $state = $workflow->getState();
            }

            if ($event instanceof ParallelEvent) {
                $event = yield from $this->executeBranches($workflow, $event, $stepId);
            }

            if ($event instanceof StopEvent || $event instanceof InterruptEvent) {
                break;
            }

            $node = $workflow->getNodeForEvent($event::class);
        }

        return $event;
    }

    /**
     * @return Generator<int, Event, mixed, ParallelEvent|InterruptEvent>
     */
    protected function executeBranches(
        WorkflowRuntimeInterface $workflow,
        ParallelEvent $parallelEvent,
        string $forkStepId,
    ): Generator {
        $requests = [];

        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            $terminal = yield from $this->executeBranch($workflow, $branchId, $branchEvent, $forkStepId);
            if ($terminal instanceof InterruptEvent) {
                array_push($requests, ...$terminal->requests);
            } elseif ($terminal instanceof StopEvent) {
                $parallelEvent->setResult($branchId, $terminal->getResult());
            }
        }

        return $requests === [] ? $parallelEvent : new InterruptEvent($requests);
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
        $branchState->set('__branchId', $branchId);
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
