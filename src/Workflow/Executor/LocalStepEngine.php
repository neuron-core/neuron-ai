<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
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

    protected ?InterruptRequest $pendingResume = null;

    public function __construct(
        protected PersistenceInterface $persistence,
    ) {
    }

    public function prepareExecution(string $workflowId, ?InterruptRequest $resume = null): void
    {
        $this->workflowId = $workflowId;

        if ($resume instanceof InterruptRequest) {
            $this->pendingResume = $resume;
        }
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

        // Resuming an interrupted step — pass the staged resume request.
        if ($cached instanceof StepResult
            && $cached->isInterrupted()
            && $this->pendingResume instanceof InterruptRequest
        ) {
            $result = $fn($this->pendingResume);

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
            $result = $fn(null);
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

        // Interrupted: the step's terminal event is an InterruptEvent. Persist an
        // interrupted marker (no throw) so the step resumes on the next run.
        if ($result->getEvent() instanceof InterruptEvent) {
            $stamped = new StepResult(
                stepId: $stepId,
                event: $result->getEvent(),
                state: $result->getState(),
                interrupt: $result->getEvent()->request,
            );
            $this->setStepResult($stepId, $stamped);
            return $stamped;
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
        $this->pendingResume = null;

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
