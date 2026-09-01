<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Terminal event signalling that the workflow is paused for external input.
 *
 * Returned by a node (or surfaced by a pausing middleware) instead of throwing
 * an exception. Like {@see StopEvent}, it terminates traversal — but the
 * workflow is paused rather than complete. The executor surfaces it as the
 * final yielded event and marks the returned {@see \NeuronAI\Workflow\WorkflowState}
 * as interrupted, carrying the request outbound.
 *
 * The executor binds and persists each request before this event reaches the
 * workflow boundary. A parallel event may therefore carry several active,
 * self-identifying requests.
 */
class InterruptEvent implements Event
{
    /**
     * @param list<InterruptRequest> $requests
     */
    public function __construct(
        public readonly array $requests,
    ) {
    }

    public static function fromRequest(InterruptRequest $request): self
    {
        return new self([$request]);
    }
}
