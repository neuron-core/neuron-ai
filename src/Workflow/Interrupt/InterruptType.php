<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

/**
 * The closed vocabulary of durable suspend categories.
 *
 * Each case maps 1:1 to a distinct scheduler capability (the mechanism that
 * produces the wakeup): external-event delivery, or a timer. Adding a type is a
 * framework concern — it requires new scheduler logic — so the type set is closed
 * by design. This is NOT the same as a closed primitive set: the InterruptRequest
 * class hierarchy stays open, and developers subclass a type (e.g.
 * WaitForEventRequest) to specialize the payload, inheriting its type().
 *
 * The enum exists so a scheduler can route wakeups via a clean match() instead of
 * an instanceof ladder.
 */
enum InterruptType: string
{
    /**
     * The workflow is suspended until an external occurrence is delivered — an
     * emitted event, or (as a specialization) a human decision. The scheduler's
     * event router produces the wakeup.
     */
    case WaitForEvent = 'wait_for_event';

    /**
     * The workflow is suspended until a clock time. The scheduler's timer wheel
     * produces the wakeup (self-generated, no external emitter).
     */
    case SleepUntil = 'sleep_until';
}
