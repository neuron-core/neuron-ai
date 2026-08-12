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
 * Hooks speak the address — the stable identity a continuation targets. An
 * address hosts at most one live run, so cancel/drop operations are safely
 * address-scoped.
 */
interface SchedulerInterface
{
    /**
     * Called by the executor immediately after a suspend has been persisted.
     *
     * The runId is the suspended run's generation stamp: record it on the
     * wakeup. When a wakeup later fires, the waking side must discard it if
     * the ignition record at the address no longer names this runId — the
     * address was completed and re-ignited in the meantime, and delivering
     * the stale wakeup would fabricate an answer for the wrong run.
     *
     * @param string           $address The suspended run's address (the resume target).
     * @param string           $runId   The generation stamp to record on the wakeup.
     * @param InterruptRequest $request The suspend request; its type() selects the wakeup strategy.
     */
    public function onSuspend(string $address, string $runId, InterruptRequest $request): void;

    /**
     * Called on a deliberate resume (a payload was delivered, inline or
     * scheduler push) — never on a payload-less revive. The scheduler should
     * cancel the wakeup this resume satisfies, so an inline resume leaves no
     * stale registration. Cancellation must be transactional: don't
     * hard-drop a wakeup if the step ultimately fails — the executor
     * re-notifies via onSuspend() if the workflow suspends again.
     *
     * @param string $address The resumed run's address.
     */
    public function onResume(string $address): void;

    /**
     * Called only on a clean, fenced terminal (StopEvent while the run still
     * holds its address) — not on a suspend, a thrown error, or a stale
     * replica completing after the address moved on. Drop ALL coordination
     * state for the address.
     *
     * @param string $address The completed run's address.
     */
    public function onComplete(string $address): void;
}
