<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Coordinates wakeups for suspended workflows. Persistence owns STATE; the
 * scheduler owns COORDINATION — deciding when a suspended workflow runs
 * again, branching on the request's type() to pick the wakeup strategy.
 * The default {@see NullScheduler} is inert: resume stays caller-driven.
 */
interface SchedulerInterface
{
    /**
     * Called by the executor immediately after a suspend has been persisted.
     *
     * @param string           $runId The suspended workflow (also its resume token).
     * @param InterruptRequest $request    The suspend request; its type() selects the wakeup strategy.
     */
    public function onSuspend(string $runId, InterruptRequest $request): void;

    /**
     * Called on a deliberate resume (inline or scheduler push) — never on
     * crash-recovery re-runs. The scheduler should cancel the wakeup this
     * resume satisfies, so an inline resume leaves no stale registration.
     * Cancellation must be transactional: don't hard-drop a wakeup if the
     * step ultimately fails — the executor re-notifies via onSuspend() if
     * the workflow suspends again.
     *
     * @param string $runId The resumed workflow.
     */
    public function onResume(string $runId): void;

    /**
     * Called only on a clean terminal (StopEvent) — not on a suspend or a
     * thrown error. Drop ALL coordination state for the workflow.
     *
     * @param string $runId The completed workflow.
     */
    public function onComplete(string $runId): void;
}
