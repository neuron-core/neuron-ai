<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use JsonSerializable;

/**
 * A durable suspend request.
 *
 * Concrete types carry only the data intrinsic to their suspend category — e.g.
 * an event name + payload (WaitForEventRequest) or a wake time (SleepUntilRequest).
 * Human-facing metadata such as a "message" belongs to the types that surface to
 * a human (ApprovalRequest), not to the base.
 */
abstract class InterruptRequest implements JsonSerializable
{
    /**
     * The suspend category — which external capability should produce the input.
     *
     * Subclasses inherit their base type's value; specialize the payload, not the
     * type (adding a type is a framework concern, see {@see InterruptType}).
     */
    abstract public function type(): InterruptType;

    /**
     * Human-readable description of this pause. There is no shared "message"
     * property: each type describes itself from its own data (an event name, a
     * wake time, or — for ApprovalRequest — a stored human-facing message).
     */
    abstract public function getMessage(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function jsonSerialize(): array;
}
