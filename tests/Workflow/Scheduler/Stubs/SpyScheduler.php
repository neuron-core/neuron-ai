<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Scheduler\Stubs;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Scheduler\SchedulerInterface;

/**
 * Test double that records every onSuspend() call so tests can assert what the
 * executor handed to the scheduler (or that it was never called).
 */
class SpyScheduler implements SchedulerInterface
{
    /** @var array<int, array{workflowId: string, request: InterruptRequest}> */
    public array $onSuspendCalls = [];

    public function onSuspend(string $workflowId, InterruptRequest $request): void
    {
        $this->onSuspendCalls[] = ['workflowId' => $workflowId, 'request' => $request];
    }
}
