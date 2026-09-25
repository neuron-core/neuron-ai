<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use DateTimeImmutable;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Executor\ActiveInterrupt;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\Segment;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Executor\WorkflowRunStore;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\Serializer;

use function in_array;
use function preg_match;
use function time;

/**
 * The lifecycle of the runs one persistence holds: it admits executions, and
 * inspects, abandons and acknowledges runs by workflow ID. It keeps nothing
 * between calls. A Workflow builds one for every call, and an application can
 * build one from the same persistence and serializer to manage runs without
 * a definition. Every mutation is fenced by the __control value it read.
 */
class WorkflowEngine
{
    public function __construct(
        protected PersistenceInterface $persistence,
        protected Serializer $serializer = new PhpSerializer(),
    ) {
    }

    /**
     * The run holding the workflow ID, or null when none does.
     *
     * @phpstan-impure Every call reads the run as persistence holds it now.
     * @throws WorkflowException
     */
    public function inspect(string $workflowId): ?WorkflowRunSnapshot
    {
        $store = $this->store($workflowId);
        $control = $store->loadControl();

        while ($control instanceof WorkflowControl) {
            $ignition = $store->loadIgnition();
            if ($ignition instanceof Ignition && $ignition->runId === $control->runId) {
                return new WorkflowRunSnapshot(
                    $control->runId,
                    $control->status,
                    $control->executionAttempt,
                    $control->interrupt?->request,
                    $workflowId,
                    $ignition->startEvent,
                );
            }

            // Only a run that ended or was replaced between the two reads may miss its ignition.
            $current = $store->loadControl();
            if ($current?->runId === $control->runId) {
                throw new WorkflowException("Run '{$control->runId}' for workflow ID '{$workflowId}' has no ignition record.");
            }
            $control = $current;
        }

        return null;
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
    public function abandon(string $workflowId, ?string $expectedRunId = null, ?int $expectedExecutionAttempt = null): bool
    {
        $store = $this->store($workflowId);

        $control = $store->loadControl();
        if (!$control instanceof WorkflowControl) {
            if ($expectedRunId !== null) {
                throw new StaleWorkflowRunException($workflowId, $expectedRunId, null);
            }

            return false;
        }

        if ($expectedRunId !== null && $control->runId !== $expectedRunId) {
            throw new StaleWorkflowRunException($workflowId, $expectedRunId, $control->runId);
        }

        if ($expectedExecutionAttempt !== null && $control->executionAttempt !== $expectedExecutionAttempt) {
            throw new WorkflowException('Cannot abandon a different execution attempt.');
        }

        if ($control->status === WorkflowStatus::Completed) {
            throw new WorkflowException(
                "Run '{$control->runId}' for workflow ID '{$workflowId}' completed: "
                . 'release it with acknowledge() instead of abandoning it.'
            );
        }

        if (
            $control->status === WorkflowStatus::Running
            && $control->leaseExpiresAt !== null
            && $control->leaseExpiresAt > time()
        ) {
            throw new WorkflowException(
                "Run '{$control->runId}' for workflow ID '{$workflowId}' appears to be executing "
                . "(lease expires at {$control->leaseExpiresAt}) and cannot be abandoned."
            );
        }

        if (!$store->deleteIfOwned()) {
            throw new WorkflowException(
                "Abandoning workflow ID '{$workflowId}' conflicted with a concurrent change; retry."
            );
        }

        return true;
    }

    /**
     * Remove a retained completion once the caller has recorded its outcome.
     *
     * @throws StaleWorkflowRunException
     * @throws WorkflowException
     */
    public function acknowledge(string $workflowId, string $expectedRunId): void
    {
        $store = $this->store($workflowId);

        $control = $this->loadControl($store, $expectedRunId);
        if ($control->runId !== $expectedRunId) {
            throw new StaleWorkflowRunException($workflowId, $expectedRunId, $control->runId);
        }

        if ($control->status !== WorkflowStatus::Completed) {
            throw new WorkflowException(
                "Run '{$expectedRunId}' for workflow ID '{$workflowId}' is not completed."
            );
        }

        if (!$store->deleteIfOwned()) {
            throw new WorkflowException(
                "Completion acknowledgement conflicted for workflow ID '{$workflowId}'."
            );
        }
    }

    /**
     * Admit one request. A request that needs execution claims the run and
     * returns the segment that owns it, under the given lease and completion
     * policy; one that doesn't, such as a retained completion or a suspended
     * run polled without an answer, returns the run's state.
     *
     * @param WorkflowState $state Fresh working state: the segment starts from it,
     *                             and it stands in for a missing checkpoint.
     * @throws RunInFlightException
     * @throws StaleWorkflowRunException
     * @throws WorkflowException
     * @internal
     */
    public function admit(
        string $workflowId,
        ExecutionRequest $request,
        WorkflowState $state,
        ?int $leaseTimeout,
        bool $retainCompletion,
    ): Segment|WorkflowState {
        $store = $this->store($workflowId);

        return $request->starting
            ? $this->startRun($store, $request, $state, $leaseTimeout, $retainCompletion)
            : $this->continueRun($store, $request, $state, $leaseTimeout, $retainCompletion);
    }

    /**
     * @throws WorkflowException
     */
    protected function startRun(
        WorkflowRunStore $store,
        ExecutionRequest $request,
        WorkflowState $state,
        ?int $leaseTimeout,
        bool $retainCompletion,
    ): Segment|WorkflowState {
        $reserved = $request->runId !== null;
        $control = new WorkflowControl(
            runId: $request->runId ?? UniqueIdGenerator::generateId('run_'),
            status: WorkflowStatus::Running,
            leaseExpiresAt: $this->leaseExpiry($leaseTimeout),
        );
        $ignition = new Ignition($control->runId, $request->event() ?? throw new WorkflowException('A start request needs its start event.'));

        $ignited = $store->initialize($control, $ignition);
        $current = $ignited ? null : $store->loadControl();

        if (!$reserved && $request->recoverFailed && $current?->status === WorkflowStatus::Failed) {
            // Fence the observed failure so recovery cannot target a generation
            // or attempt that another worker replaced while we were reading.
            return $this->continueRun(
                $store,
                ExecutionRequest::resume(expectedRunId: $current->runId, expectedExecutionAttempt: $current->executionAttempt),
                $state,
                $leaseTimeout,
                $retainCompletion,
            );
        }

        // A dead generation (failed, or lease expired) is swept and replaced; the
        // delete is fenced by the bytes just read, so a concurrent claimant wins.
        if (
            !$reserved && !$ignited
            && (!$current instanceof WorkflowControl || ($this->isDeadGeneration($current) && $store->deleteIfOwned()))
        ) {
            $ignited = $store->initialize($control, $ignition);
        }

        if (!$ignited) {
            throw $current instanceof WorkflowControl
                ? new RunInFlightException(
                    workflowId: $store->workflowId,
                    runId: $current->runId,
                    status: $current->status,
                    executionAttempt: $current->executionAttempt,
                    leaseExpiresAt: $current->leaseExpiresAt,
                    interrupt: $current->interrupt?->request,
                )
                : new WorkflowException(
                    "Cannot ignite a new run for workflow ID '{$store->workflowId}': "
                    . 'a concurrent process is changing it. Retry the ignition.'
                );
        }

        return $this->openSegment($store, $ignition, $state, $leaseTimeout, $retainCompletion);
    }

    protected function isDeadGeneration(WorkflowControl $control): bool
    {
        return $control->status === WorkflowStatus::Failed
            || ($control->status === WorkflowStatus::Running
                && $control->leaseExpiresAt !== null
                && $control->leaseExpiresAt <= time());
    }

    /**
     * @throws StaleWorkflowRunException
     * @throws WorkflowException
     */
    protected function continueRun(
        WorkflowRunStore $store,
        ExecutionRequest $request,
        WorkflowState $state,
        ?int $leaseTimeout,
        bool $retainCompletion,
    ): Segment|WorkflowState {
        $workflowId = $store->workflowId;
        $signalName = $request->signal;
        $control = $this->loadControl($store, $request->runId);
        $runId = $control->runId;

        if ($request->runId !== null && $request->runId !== $runId) {
            throw new StaleWorkflowRunException($workflowId, $request->runId, $runId);
        }

        if (
            $request->executionAttempt !== null
            && $request->executionAttempt !== $control->executionAttempt
        ) {
            throw new WorkflowException(
                "Stale continuation for workflow ID '{$workflowId}': expected execution attempt "
                . "{$request->executionAttempt}, current attempt is {$control->executionAttempt}."
            );
        }

        $ignition = $store->loadIgnition();
        if (!$ignition instanceof Ignition) {
            throw new WorkflowException(
                "Run '{$runId}' for workflow ID '{$workflowId}' has no ignition record."
            );
        }
        if ($ignition->runId !== $runId) {
            throw new WorkflowException(
                "Workflow ID '{$workflowId}' has mismatched __control and __ignition generations."
            );
        }

        if ($control->status === WorkflowStatus::Completed) {
            if ($signalName !== null) {
                throw new WorkflowException(
                    "No active interruption for workflow ID '{$workflowId}' is waiting for signal '{$signalName}'."
                );
            }

            return $store->loadOutcome() ?? throw new WorkflowException("Completed run '{$runId}' has no retained outcome.");
        }

        $active = $control->interrupt;
        if ($signalName !== null) {
            if (!$active?->request instanceof WaitForEventRequest || $active->request->getEventName() !== $signalName) {
                throw new WorkflowException("The current interruption is not waiting for signal '{$signalName}'.");
            }
        }

        $payload = $request->payload();
        if ($payload !== null) {
            if (!$active instanceof ActiveInterrupt) {
                throw new WorkflowException('There is no current interruption to answer.');
            }
            if (!in_array($control->status, [WorkflowStatus::Suspended, WorkflowStatus::Failed], true)) {
                throw new WorkflowException("Run '{$runId}' is '{$control->status->value}', not suspended.");
            }
            $input = ResumeInput::event($active->request, $payload);
        } else {
            $this->assertInputlessContinuationAllowed($control, $workflowId);
            $input = $this->dueInput($control);
        }
        if ($input instanceof ResumeInput) {
            $active->request->validate($input);
            $control = $control->withInput($input);
        }

        if ($control->interrupt instanceof ActiveInterrupt && !$control->interrupt->input instanceof ResumeInput) {
            $state = $store->loadCheckpoint() ?? $state;
            $settled = $control->status === WorkflowStatus::Suspended ? $control : $control->claim(null)->suspended();
            $state->setExecutionMetadata($workflowId, $runId, $settled->executionAttempt);
            $state->markAsSuspended($control->interrupt->request);
            $checkpoint = clone $state;
            $checkpoint->markAsSuspended(null);
            $store->commitCheckpoint($checkpoint, $settled);
            return $state;
        }

        $store->replaceControl($control->claim($this->leaseExpiry($leaseTimeout)));

        return $this->openSegment($store, $ignition, $state, $leaseTimeout, $retainCompletion);
    }

    protected function dueInput(WorkflowControl $control): ?ResumeInput
    {
        $active = $control->interrupt;
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

    /**
     * @throws WorkflowException
     */
    protected function assertInputlessContinuationAllowed(WorkflowControl $control, string $workflowId): void
    {
        if (
            $control->status === WorkflowStatus::Running
            && $control->leaseExpiresAt !== null
            && $control->leaseExpiresAt > time()
        ) {
            throw new WorkflowException(
                "The run for workflow ID '{$workflowId}' appears to be executing "
                . "(lease expires at {$control->leaseExpiresAt}) — cannot continue without input."
            );
        }
    }

    /**
     * @throws StaleWorkflowRunException
     * @throws WorkflowException
     */
    protected function loadControl(WorkflowRunStore $store, ?string $expectedRunId): WorkflowControl
    {
        $control = $store->loadControl();
        if ($control instanceof WorkflowControl) {
            return $control;
        }

        if ($expectedRunId !== null) {
            throw new StaleWorkflowRunException($store->workflowId, $expectedRunId, null);
        }

        throw new WorkflowException(
            "No run in flight for workflow ID '{$store->workflowId}' — nothing to continue."
        );
    }

    protected function openSegment(
        WorkflowRunStore $store,
        Ignition $ignition,
        WorkflowState $state,
        ?int $leaseTimeout,
        bool $retainCompletion,
    ): Segment {
        $context = new ExecutionContext($store->workflowId, $ignition->runId, $store->control()->executionAttempt, $ignition);

        return new Segment($store, $context, $state, $leaseTimeout, $retainCompletion);
    }

    protected function leaseExpiry(?int $leaseTimeout): ?int
    {
        return $leaseTimeout === null ? null : time() + $leaseTimeout;
    }

    /**
     * Every workflow ID enters the engine here, so reserved partitions are
     * refused on every path.
     *
     * @throws WorkflowException
     */
    protected function store(string $workflowId): WorkflowRunStore
    {
        if (preg_match('/^(?!__)[^\\x00-\\x1F\\x7F]{1,255}$/u', $workflowId) !== 1) {
            throw new WorkflowException('Invalid workflow ID: use a nonempty address of at most 255 characters without control characters or the __ prefix.');
        }

        return new WorkflowRunStore($this->persistence, $this->serializer, $workflowId);
    }
}
