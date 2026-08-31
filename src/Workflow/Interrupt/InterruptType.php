<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

/**
 * The closed vocabulary of durable suspend categories.
 *
 * Each case maps 1:1 to a distinct coordination capability (the mechanism that
 * produces the wakeup): external-event delivery, or a timer. Adding a type is a
 * framework concern — it requires a new resume protocol — so the type set is closed
 * by design. This is NOT the same as a closed primitive set: the InterruptRequest
 * class hierarchy stays open, and developers subclass a type (e.g.
 * WaitForEventRequest) to specialize the payload, inheriting its type().
 *
 * The enum exists so coordination code can route inputs via a clean match() instead of
 * an instanceof ladder.
 */
enum InterruptType: string
{
    /**
     * The workflow is suspended until an external occurrence is delivered — an
     * emitted event, or (as a specialization) a human decision. An external
     * event router produces the input.
     */
    case WaitForEvent = 'wait_for_event';

    /**
     * The workflow is suspended until a clock time. An external timer service
     * produces the input (self-generated, no external emitter).
     */
    case SleepUntil = 'sleep_until';
}
