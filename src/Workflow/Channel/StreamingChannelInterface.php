<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Channel;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Delivery seam: where in-flight output goes, decoupled from who holds the
 * generator. The channel has two delivery ports:
 *
 *  - send(object):       a native framework chunk (TextChunk, ToolCallChunk,
 *                        custom node yields) — used when NO stream adapter is
 *                        attached to the workflow.
 *  - sendLine(string):   an adapted protocol line — used when a stream adapter
 *                        IS attached; this carries the adapter's transform()
 *                        output plus its start()/end() framing sequences.
 *
 * The framework picks one port per run based on whether an adapter is
 * attached (Workflow::setStreamAdapter()); a channel never receives both
 * shapes in the same segment.
 *
 * A channel instance is segment-scoped: constructed for one events()
 * consumption, so implementations may hold per-segment state without
 * cross-run leakage.
 *
 * Channels should not throw; the framework guards every call regardless — a
 * channel error never fails the run (see Workflow::fireChannel()).
 */
interface StreamingChannelInterface
{
    /**
     * A yielded stream item (TextChunk, ToolCallChunk, custom node yields).
     * Called only when no stream adapter is attached. Never an InterruptEvent
     * — terminals are explicit methods below.
     */
    public function send(object $item): void;

    /**
     * An adapted protocol line — the output of a stream adapter's transform(),
     * or one of its start()/end() framing lines. Called only when a stream
     * adapter is attached to the workflow.
     */
    public function sendLine(string $line): void;

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
