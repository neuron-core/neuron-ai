<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Scheduler\Stubs;

use NeuronAI\Workflow\Executor\SchedulerInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Test double that records every scheduler lifecycle call so tests can assert what
 * the executor handed to the scheduler (or that a hook was never called).
 */
class SpyScheduler implements SchedulerInterface
{
    /** @var array<int, array{runId: string, request: InterruptRequest}> */
    public array $onSuspendCalls = [];

    /** @var array<int, string> */
    public array $onResumeCalls = [];

    /** @var array<int, string> */
    public array $onCompleteCalls = [];

    public function onSuspend(string $runId, InterruptRequest $request): void
    {
        $this->onSuspendCalls[] = ['runId' => $runId, 'request' => $request];
    }

    public function onResume(string $runId): void
    {
        $this->onResumeCalls[] = $runId;
    }

    public function onComplete(string $runId): void
    {
        $this->onCompleteCalls[] = $runId;
    }
}
