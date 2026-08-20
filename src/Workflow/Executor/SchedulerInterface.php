<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Coordinates wakeups for suspended workflows. Persistence owns STATE; the
 * scheduler owns COORDINATION — deciding when a suspended workflow runs
 * again, branching on the request's type() to pick the wakeup strategy.
 * The default {@see NullScheduler} is inert: resume stays caller-driven.
 *
 * Hooks speak the workflow ID — the stable identity a continuation targets —
 * and the run ID generation. Coordinators must scope every mutation to both,
 * so a delayed callback from an older generation cannot affect the current
 * run under the same workflow ID.
 */
interface SchedulerInterface
{
    /**
     * Called by the executor immediately after a suspend has been persisted.
     *
     * The runId is the suspended run's generation stamp: record it on the
     * wakeup. When a wakeup later fires, the waking side must discard it if
     * the ignition record under the workflow ID no longer names this runId —
     * the workflow ID was completed and re-ignited in the meantime, and
     * delivering the stale wakeup would fabricate an answer for the wrong run.
     *
     * @param string           $workflowId The suspended run's workflow ID (the resume target).
     * @param string           $runId      The generation stamp to record on the wakeup.
     * @param InterruptRequest $request    The suspend request; its type() selects the wakeup strategy.
     */
    public function onSuspend(string $workflowId, string $runId, InterruptRequest $request): void;

    /**
     * Called on a deliberate resume (a payload was delivered, inline or
     * scheduler push) — never on a payload-less revive. The scheduler should
     * cancel the wakeup this resume satisfies, so an inline resume leaves no
     * stale registration. Cancellation must be transactional: don't
     * hard-drop a wakeup if the step ultimately fails — the executor
     * re-notifies via onSuspend() if the workflow suspends again.
     *
     * @param string $workflowId The resumed run's workflow ID.
     * @param string $runId The resumed run's generation stamp.
     */
    public function onResume(string $workflowId, string $runId): void;

    /**
     * Called only on a clean, fenced terminal (StopEvent while the run still
     * holds its workflow ID) — not on a suspend, a thrown error, or a stale
     * replica completing after the workflow ID moved on. Drop coordination
     * state only for this workflow ID and run ID generation.
     *
     * @param string $workflowId The completed run's workflow ID.
     * @param string $runId The completed run's generation stamp.
     */
    public function onComplete(string $workflowId, string $runId): void;
}
