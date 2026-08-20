<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Scheduler\Stubs;

use NeuronAI\Workflow\Executor\SchedulerInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Records every hook invocation for assertions.
 */
class SpyScheduler implements SchedulerInterface
{
    /** @var array<int, array{workflowId: string, runId: string, request: InterruptRequest}> */
    public array $onSuspendCalls = [];

    /** @var array<int, array{workflowId: string, runId: string}> */
    public array $onResumeCalls = [];

    /** @var array<int, array{workflowId: string, runId: string}> */
    public array $onCompleteCalls = [];

    public function onSuspend(string $workflowId, string $runId, InterruptRequest $request): void
    {
        $this->onSuspendCalls[] = ['workflowId' => $workflowId, 'runId' => $runId, 'request' => $request];
    }

    public function onResume(string $workflowId, string $runId): void
    {
        $this->onResumeCalls[] = ['workflowId' => $workflowId, 'runId' => $runId];
    }

    public function onComplete(string $workflowId, string $runId): void
    {
        $this->onCompleteCalls[] = ['workflowId' => $workflowId, 'runId' => $runId];
    }
}
