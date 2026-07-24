<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

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

    /**
     * Called by the executor when a suspended workflow is resumed — whether inline
     * (a caller re-invokes resume() with a payload, e.g. a human approval from a UI
     * controller) or via a scheduler push wakeup. The scheduler should cancel the
     * wakeup this resume satisfies, so an inline resume does not leave a stale
     * timer/event subscription behind.
     *
     * This fires only on a deliberate resume (resume()), not on crash-recovery
     * re-runs (which replay cached steps via run()). Cancellation is contingent on
     * the resume committing: a real scheduler treats it transactionally and must
     * not hard-drop a wakeup if the step ultimately fails — the executor
     * re-notifies via onSuspend() if the workflow suspends again. The default
     * {@see NullScheduler} is inert regardless.
     *
     * The resume payload is not passed: the scheduler cancels by workflow id, and a
     * scheduler that fired a timeout already knows it did.
     *
     * @param string $workflowId The resumed workflow.
     */
    public function onResume(string $workflowId): void;

    /**
     * Called by the executor when a workflow reaches a clean terminal (StopEvent).
     * The scheduler should drop ALL coordination state for the workflow (timers,
     * subscriptions). Not called on a suspend or on a thrown error — only on
     * completion.
     *
     * @param string $workflowId The completed workflow.
     */
    public function onComplete(string $workflowId): void;
}
