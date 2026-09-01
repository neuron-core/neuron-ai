<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Channel;

use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Delivery seam: where in-flight output goes, decoupled from who holds the
 * generator. Two ports — send(object) for native chunks (no stream adapter
 * attached) and sendLine(string) for adapted protocol lines (adapter
 * attached); a channel never receives both shapes in the same segment.
 *
 * A channel instance is segment-scoped (one events() consumption), so it may
 * hold per-segment state without cross-run leakage. Channels should not
 * throw; the framework guards every call regardless — a channel error never
 * fails the run (see Workflow::fireChannel()).
 */
interface StreamingChannelInterface
{
    /**
     * A yielded stream item. Never an InterruptEvent — terminals are the
     * explicit methods below.
     */
    public function send(object $item): void;

    /**
     * An adapted protocol line — a stream adapter's transform() output or
     * one of its start()/end() framing lines.
     */
    public function sendLine(string $line): void;

    /**
     * Run segment ended with one or more active interrupt requests.
     */
    public function suspended(WorkflowState $state): void;

    /** Run segment ended cleanly. */
    public function completed(WorkflowState $state, string $workflowId): void;

    /**
     * Run segment died on an unhandled throwable. Notification only — the
     * exception propagates to the caller regardless.
     */
    public function failed(Throwable $exception, string $workflowId): void;
}
