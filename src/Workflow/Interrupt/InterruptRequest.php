<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use JsonSerializable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Resume\ResumeInput;

use function array_merge;

/**
 * The canonical portable description of an active workflow interruption.
 *
 * Concrete types carry only the data intrinsic to their interrupt category — e.g.
 * an event name + payload (WaitForEventRequest) or a wake time (SleepUntilRequest).
 * Human-facing metadata such as a "message" belongs to the types that surface to
 * a human (ApprovalRequest), not to the base.
 */
abstract class InterruptRequest implements JsonSerializable
{
    protected ?int $id = null;

    final public function withId(int $id): static
    {
        if ($id < 1) {
            throw new WorkflowException('An interrupt ID must be a positive integer.');
        }

        $request = clone $this;
        $request->id = $id;

        return $request;
    }

    final public function getId(): int
    {
        if ($this->id === null) {
            throw new WorkflowException('The interrupt request has not been activated yet.');
        }

        return $this->id;
    }

    /**
     * The interrupt category — which external capability should produce the input.
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

    abstract public function validate(ResumeInput $input): void;

    /** @return array<string, mixed> */
    abstract protected function coordinationData(): array;

    /** @return array<string, mixed> */
    protected function metadata(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    final public function jsonSerialize(): array
    {
        return array_merge(
            [
                'interruptId' => $this->id,
                'type' => $this->type()->value,
            ],
            $this->coordinationData(),
            $this->metadata(),
        );
    }
}
