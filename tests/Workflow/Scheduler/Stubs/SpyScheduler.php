<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Scheduler\Stubs;

use NeuronAI\Workflow\Executor\Scheduler\SchedulerInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Test double that records every scheduler lifecycle call so tests can assert what
 * the executor handed to the scheduler (or that a hook was never called).
 */
class SpyScheduler implements SchedulerInterface
{
    /** @var array<int, array{workflowId: string, stepId: string, request: InterruptRequest}> */
    public array $onSuspendCalls = [];

    /** @var array<int, array{workflowId: string, request: InterruptRequest}> */
    public array $onResumeCalls = [];

    /** @var array<int, string> */
    public array $onCompleteCalls = [];

    public function onSuspend(string $workflowId, string $stepId, InterruptRequest $request): void
    {
        $this->onSuspendCalls[] = ['workflowId' => $workflowId, 'stepId' => $stepId, 'request' => $request];
    }

    public function onResume(string $workflowId, InterruptRequest $request): void
    {
        $this->onResumeCalls[] = ['workflowId' => $workflowId, 'request' => $request];
    }

    public function onComplete(string $workflowId): void
    {
        $this->onCompleteCalls[] = $workflowId;
    }
}
