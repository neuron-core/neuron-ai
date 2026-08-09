<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Channel;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Delivery seam: where in-flight output goes, decoupled from who holds the
 * generator. The channel receives framework-native objects, never protocol
 * strings — formatting is per-consumer edge work (see StreamAdapterChannel).
 *
 * A channel instance is segment-scoped: constructed for one events()
 * consumption, so implementations may hold per-segment state (e.g. a lazy
 * protocol start) without cross-run leakage.
 *
 * Channels should not throw; the framework guards every call regardless — a
 * channel error never fails the run (see Workflow::fireChannel()).
 */
interface ChannelInterface
{
    /**
     * A yielded stream item (TextChunk, ToolCallChunk, custom node yields).
     * Never an InterruptEvent — terminals are explicit methods below.
     */
    public function send(object $item): void;

    /**
     * Run segment ended in a suspension. The request is the live, in-process
     * object — never serialized. Contract for consumers: UPSERT by runId
     * ("current pending request"), never append — replay-by-rerun re-emits
     * this on every re-suspension of the same run.
     */
    public function suspended(InterruptRequest $request, string $runId): void;

    /** Run segment ended cleanly. */
    public function completed(WorkflowState $state, string $runId): void;

    /**
     * Run segment died on an unhandled throwable. Notification only — the
     * exception propagates to the caller regardless.
     */
    public function failed(Throwable $exception, string $runId): void;
}
