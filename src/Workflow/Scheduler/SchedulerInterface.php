<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Scheduler;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Coordinates wakeups for suspended workflows.
 *
 * The persistence layer owns STATE; the scheduler owns COORDINATION — deciding
 * when a suspended workflow should run again. A scheduler is notified once per
 * suspend, after the interrupted state has been persisted, and branches on the
 * request's type() to register the appropriate wakeup (timer wheel for
 * SleepUntil, event-router subscription for WaitForEvent, nothing for types
 * resumed externally).
 *
 * The default {@see NullScheduler} is inert: it never wakes anything, preserving
 * the caller-driven model where a caller re-invokes run() to resume. Concrete
 * schedulers (a queue/cron worker, a cloud push/callback platform) implement this
 * to drive wakeups themselves.
 */
interface SchedulerInterface
{
    /**
     * Called by the executor immediately after a suspend has been persisted.
     *
     * @param string           $workflowId The suspended workflow (also its resume token).
     * @param InterruptRequest $request    The suspend request; its type() selects the wakeup strategy.
     */
    public function onSuspend(string $workflowId, InterruptRequest $request): void;
}
