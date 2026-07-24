<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Inert scheduler: never registers or fires any wakeup.
 *
 * This is the default. It preserves the caller-driven model — a suspended
 * workflow stays suspended until a caller re-invokes resume() with a wake.
 * It is also the scheduler used in tests and by the OSS distribution; real
 * coordination (timers/events/cloud) is provided by alternative implementations.
 */
class NullScheduler implements SchedulerInterface
{
    public function onSuspend(string $workflowId, InterruptRequest $request): void
    {
        // Intentionally inert.
    }

    public function onResume(string $workflowId): void
    {
        // Intentionally inert.
    }

    public function onComplete(string $workflowId): void
    {
        // Intentionally inert.
    }
}
