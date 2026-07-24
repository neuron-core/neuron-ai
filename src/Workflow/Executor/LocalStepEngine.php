<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Throwable;

/**
 * In-process replay engine, the local StepEngineInterface implementation.
 *
 * Persists every executed node as a StepResult. On re-run, previously completed
 * steps are returned from cache without re-executing the node; interrupted steps
 * resume from the stored InterruptRequest; failed steps (marked after an
 * unhandled throwable) retry.
 *
 * Replay is keyed by step id alone: step ids are unique within a run (monotonic
 * traversal index, a branch prefix, a per-name memo suffix), so a completed,
 * non-interrupted, non-failed cached result is always a prior run's work and is
 * safe to skip. There is no generation counter and no scan of stored steps.
 */
class LocalStepEngine implements StepEngineInterface
{
    protected string $workflowId;

    /**
     * The inbound resume wake staged by prepareExecution(). Null means this run
     * is a fresh start or a crash-recovery replay (no resume); a non-null array
     * (even empty) means a deliberate resume — the interrupted step consumes it.
     */
    protected ?array $pendingWake = null;

    protected bool $pendingTimedOut = false;

    public function __construct(
        protected PersistenceInterface $persistence,
    ) {
    }

    public function prepareExecution(string $workflowId, ?array $wake = null, bool $timedOut = false): void
    {
        $this->workflowId = $workflowId;
        $this->pendingWake = $wake;
        $this->pendingTimedOut = $timedOut;
    }

    public function runStep(string $stepId, callable $fn): StepResult
    {
        $cached = $this->getStepResult($stepId);

        // Memoized: return a previously completed result without re-executing.
        // Failed steps are never replayed from cache — they must retry.
        if ($cached instanceof StepResult
            && !$cached->isInterrupted()
            && !$cached->isFailed()
        ) {
            return $cached;
        }

        // Resuming an interrupted step — inject the staged inbound wake.
        if ($cached instanceof StepResult
            && $cached->isInterrupted()
            && $this->pendingWake !== null
        ) {
            $result = $fn($this->pendingWake, $this->pendingTimedOut);

            $stamped = new StepResult(
                stepId: $result->getStepId(),
                event: $result->getEvent(),
                state: $result->getState(),
                output: $result->getOutput(),
            );
            $this->setStepResult($stepId, $stamped);

            return $stamped;
        }

        // Execute the callable.
        try {
            $result = $fn(null, false);
        } catch (Throwable $e) {
            // Record a failed-step marker for crash observability, then rethrow.
            // On recovery the marker makes this step retry (it is never replayed from cache).
            $stamped = new StepResult(
                stepId: $stepId,
                error: ['message' => $e->getMessage(), 'class' => $e::class],
            );
            $this->setStepResult($stepId, $stamped);
            throw $e;
        }

        // Interrupted: the step's terminal event is an InterruptEvent. Persist only an
        // interrupted marker (no throw) so the step resumes on the next run. The
        // InterruptRequest rides the event outbound (→ onSuspend / returned state) but
        // is NOT persisted — it is rebuilt by re-running the node on resume, which keeps
        // developer objects stuffed into a request out of the serializer.
        if ($result->getEvent() instanceof InterruptEvent) {
            $this->setStepResult($stepId, new StepResult(stepId: $stepId, interrupted: true));
            return $result;
        }

        // Persist the completed result.
        $stamped = new StepResult(
            stepId: $result->getStepId(),
            event: $result->getEvent(),
            state: $result->getState(),
            output: $result->getOutput(),
        );
        $this->setStepResult($stepId, $stamped);

        return $stamped;
    }

    public function deleteSteps(): void
    {
        $this->pendingWake = null;
        $this->pendingTimedOut = false;

        $this->persistence->delete($this->workflowId);
    }

    /**
     * Return a successfully-completed step result, or null.
     *
     * Same cache-hit guard as runStep() — interrupted or failed steps are never
     * returned, only completed results from a prior run. Used by the memoizer to
     * recall a durable value WITHOUT running anything, which is what lets a node
     * skip non-replayable work like a provider stream.
     */
    public function getStep(string $stepId): ?StepResult
    {
        $cached = $this->getStepResult($stepId);

        if ($cached instanceof StepResult
            && !$cached->isInterrupted()
            && !$cached->isFailed()
        ) {
            return $cached;
        }

        return null;
    }

    protected function getStepResult(string $stepId): ?StepResult
    {
        return $this->persistence->load($this->workflowId, $stepId);
    }

    protected function setStepResult(string $stepId, StepResult $result): void
    {
        $this->persistence->save($this->workflowId, $stepId, $result);
    }
}
