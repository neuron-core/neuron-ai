<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Deeplinq\Context;
use Deeplinq\StepPendingException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;

/**
 * StepEngine backed by the Deeplinq durable execution platform.
 *
 * Each runStep() call wraps ctx->step->run() for memoization.
 * Interrupts use a 3-step pattern: execute → waitForEvent → re-execute.
 * Re-interrupts are handled via an internal loop.
 */
class DeeplinqStepEngine implements StepEngine
{
    public $workflowId;
    public function __construct(
        protected Context $ctx,
    ) {
    }

    public function prepareExecution(string $workflowId, ?InterruptRequest $resume = null): void
    {
        // No-op: Deeplinq platform handles replay via ctx->step->run() memoization.
    }

    public function deleteSteps(): void
    {
        // No-op: Deeplinq platform manages step lifecycle.
    }

    public function runStep(string $stepId, callable $fn): StepResult
    {
        return $this->runWithInterruptLoop($stepId, $fn, null, '');
    }

    /**
     * Execute a step with interrupt/re-resume loop support.
     *
     * Each interrupt cycle creates two additional platform steps:
     *   - {stepId}{suffix}.interrupt → waitForEvent for user response
     *   - {stepId}{suffix}.resumed   → re-execute with resume request
     *
     * @param callable(?InterruptRequest): StepResult $fn
     * @throws StepPendingException
     */
    protected function runWithInterruptLoop(
        string $stepId,
        callable $fn,
        ?InterruptRequest $resumeRequest,
        string $suffix,
    ): StepResult {
        $currentStepId = $stepId . $suffix;

        $packed = $this->ctx->step->run($currentStepId, function () use ($fn, $resumeRequest): array {
            try {
                $result = $fn($resumeRequest);
                return $this->packStepResult($result);
            } catch (WorkflowInterrupt $interrupt) {
                return $this->packInterrupt($interrupt);
            }
        });

        if ($packed['interrupted'] ?? false) {
            $resumeData = $this->ctx->step->waitForEvent(
                $currentStepId . '.interrupt',
                'workflow/interrupt/' . $this->workflowId,
                '7d',
            );

            $nextResume = unserialize(base64_decode((string) $resumeData['resume']));

            return $this->runWithInterruptLoop(
                $stepId,
                $fn,
                $nextResume,
                $suffix . '.r',
            );
        }

        return $this->unpackToStepResult($packed);
    }

    protected function packStepResult(StepResult $result): array
    {
        return [
            'event' => base64_encode(serialize($result->getEvent())),
            'state' => base64_encode(serialize($result->getState())),
        ];
    }

    protected function packInterrupt(WorkflowInterrupt $interrupt): array
    {
        return [
            'interrupted' => true,
            'state' => base64_encode(serialize($interrupt->getState())),
            'request' => base64_encode(serialize($interrupt->getRequest())),
        ];
    }

    protected function unpackToStepResult(array $packed): StepResult
    {
        $event = unserialize(base64_decode((string) $packed['event']));
        $state = unserialize(base64_decode((string) $packed['state']));

        return new StepResult(
            stepId: $event instanceof Event ? $event::class : '',
            event: $event,
            state: $state,
        );
    }
}
