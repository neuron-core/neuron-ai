<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

class LocalStepEngine implements StepEngine
{
    /** @var array<string, StepResult> keyed by stepId */
    protected array $steps = [];

    protected int $generation = 0;

    protected ?InterruptRequest $pendingResume = null;
    protected ?string $interruptedStepId = null;

    public function __construct(
        protected ?StepStoreInterface $store = null,
        protected string $workflowId = '',
    ) {
    }

    public function prepareExecution(): void
    {
        $this->generation++;
    }

    public function runStep(string $stepId, callable $fn): StepResult
    {
        $cached = $this->getStepResult($stepId);

        // Memoized: return cached result from a previous generation
        if ($cached instanceof StepResult
            && !$cached->isInterrupted()
            && $cached->getGeneration() < $this->generation
        ) {
            return $cached;
        }

        // Determine if this step has a pending resume request
        $resumeRequest = ($stepId === $this->interruptedStepId)
            ? $this->pendingResume
            : null;

        // Execute the callable
        $result = $fn($resumeRequest);

        // Save internally with current generation
        $stamped = new StepResult(
            stepId: $result->getStepId(),
            event: $result->getEvent(),
            state: $result->getState(),
            interrupt: $result->getInterrupt(),
            generation: $this->generation,
        );
        $this->setStepResult($stepId, $stamped);

        return $stamped;
    }

    public function interruptStep(string $stepId, InterruptRequest $request): void
    {
        $result = new StepResult(
            stepId: $stepId,
            interrupt: $request,
            generation: $this->generation,
        );
        $this->setStepResult($stepId, $result);
        $this->interruptedStepId = $stepId;
    }

    public function setResumeRequest(InterruptRequest $request): void
    {
        $this->pendingResume = $request;
    }

    public function deleteSteps(): void
    {
        $this->steps = [];
        $this->generation = 0;
        $this->pendingResume = null;
        $this->interruptedStepId = null;

        if ($this->workflowId !== '') {
            $this->store?->delete($this->workflowId);
        }
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
        if ($this->store instanceof StepStoreInterface && $this->workflowId !== '') {
            return $this->store->load($this->workflowId, $stepId);
        }

        return $this->steps[$stepId] ?? null;
    }

    protected function setStepResult(string $stepId, StepResult $result): void
    {
        if ($this->store instanceof StepStoreInterface && $this->workflowId !== '') {
            $this->store->save($this->workflowId, $stepId, $result);
            return;
        }

        $this->steps[$stepId] = $result;
    }
}
