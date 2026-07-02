<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Throwable;

use function max;

/**
 * In-process replay engine used internally by the local WorkflowExecutor.
 *
 * Persists every executed node as a StepResult. On re-run, completed steps from
 * an older generation are returned from cache without re-executing the node;
 * interrupted steps resume from the stored InterruptRequest; failed steps
 * (marked after an unhandled throwable) retry.
 */
class LocalStepEngine
{
    protected string $workflowId;

    protected int $generation = 0;

    protected ?InterruptRequest $pendingResume = null;

    public function __construct(
        protected PersistenceInterface $persistence,
    ) {
    }

    public function prepareExecution(string $workflowId, ?InterruptRequest $resume = null): void
    {
        $this->workflowId = $workflowId;
        $storedMax = $this->persistence->getMaxGeneration($workflowId);
        $this->generation = max($this->generation + 1, $storedMax + 1);
        if ($resume instanceof InterruptRequest) {
            $this->pendingResume = $resume;
        }
    }

    public function runStep(string $stepId, callable $fn): StepResult
    {
        $cached = $this->getStepResult($stepId);

        // Memoized: return cached result from a previous generation.
        // Failed steps are never replayed from cache — they must retry.
        if ($cached instanceof StepResult
            && !$cached->isInterrupted()
            && !$cached->isFailed()
            && $cached->getGeneration() < $this->generation
        ) {
            return $cached;
        }

        // Resuming an interrupted step — pass the stored resume request
        if ($cached instanceof StepResult
            && $cached->isInterrupted()
            && $this->pendingResume instanceof InterruptRequest
        ) {
            $result = $fn($this->pendingResume);

            $stamped = new StepResult(
                stepId: $result->getStepId(),
                event: $result->getEvent(),
                state: $result->getState(),
                generation: $this->generation,
                output: $result->getOutput(),
            );
            $this->setStepResult($stepId, $stamped);

            return $stamped;
        }

        // Execute the callable
        try {
            $result = $fn(null);
        } catch (WorkflowInterrupt $interrupt) {
            $stamped = new StepResult(
                stepId: $stepId,
                interrupt: $interrupt->getRequest(),
                generation: $this->generation,
            );
            $this->setStepResult($stepId, $stamped);
            throw $interrupt;
        } catch (Throwable $e) {
            // Record a failed-step marker for crash observability, then rethrow.
            // On recovery the marker makes this step retry (it is never replayed from cache).
            $stamped = new StepResult(
                stepId: $stepId,
                generation: $this->generation,
                error: ['message' => $e->getMessage(), 'class' => $e::class],
            );
            $this->setStepResult($stepId, $stamped);
            throw $e;
        }

        // Save internally with current generation
        $stamped = new StepResult(
            stepId: $result->getStepId(),
            event: $result->getEvent(),
            state: $result->getState(),
            generation: $this->generation,
            output: $result->getOutput(),
        );
        $this->setStepResult($stepId, $stamped);

        return $stamped;
    }

    public function deleteSteps(): void
    {
        $this->generation = 0;
        $this->pendingResume = null;

        $this->persistence->delete($this->workflowId);
    }

    /**
     * Get a stored step result by step ID.
     */
    public function getStep(string $stepId): ?StepResult
    {
        return $this->getStepResult($stepId);
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
