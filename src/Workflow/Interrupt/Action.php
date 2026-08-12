<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use JsonSerializable;

/**
 * A single action awaiting a human decision. A pure outbound value object:
 * it is part of an {@see ApprovalRequest} that the caller renders, and is immutable once
 * constructed. The human's decision travels inbound as a resume payload — never by
 * mutating this object.
 */
class Action implements JsonSerializable
{
    /**
     * @param string             $id          Unique identifier for this action (the tool callId)
     * @param string             $name        Short human-readable name
     * @param string|null        $description Detailed description of what this action does
     * @param ActionDecision     $decision    Current decision state
     * @param string|null        $feedback    Optional feedback from the approver (reject reason)
     * @param string|null        $reason      Why approval is being requested (declared by the
     *                                        tool or the middleware config — outbound)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ActionDecision $decision = ActionDecision::Pending,
        public readonly ?string $feedback = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function isPending(): bool
    {
        return $this->decision->isPending();
    }

    public function isApproved(): bool
    {
        return $this->decision->isApproved();
    }

    public function isRejected(): bool
    {
        return $this->decision->isRejected();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'decision' => $this->decision->value,
            'feedback' => $this->feedback,
            'reason' => $this->reason,
        ];
    }
}
