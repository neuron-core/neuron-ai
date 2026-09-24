<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Delivery seam: where in-flight output goes, decoupled from who holds the
 * generator. A channel speaks the attached stream adapter's protocol: it
 * receives the adapter's ProtocolEvents, framing included, plus the segment
 * lifecycle. Without an adapter only the lifecycle methods are called.
 *
 * A channel serves one segment (one events() consumption): the Workflow
 * builds one for every segment and notifies it of that segment's outcome.
 * Transport failures should throw: Workflow reports them as ChannelError
 * without failing the run.
 */
interface StreamingChannelInterface
{
    /**
     * A protocol event produced by the stream adapter, in stream order.
     */
    public function send(ProtocolEvent $event): void;

    /**
     * Run segment ended with the current interruption.
     */
    public function interrupted(WorkflowState $state): void;

    /** Run segment ended cleanly. */
    public function completed(WorkflowState $state, string $workflowId): void;

    /**
     * Run segment died on an unhandled throwable. Notification only — the
     * exception propagates to the caller regardless.
     */
    public function failed(Throwable $exception, string $workflowId): void;
}
