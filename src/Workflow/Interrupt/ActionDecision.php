<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

/**
 * Represents the decision state of an interrupt action.
 *
 * Actions in an interrupt request can be in different states:
 * - Pending: Awaiting user decision
 * - Approved: User approved the action
 * - Rejected: User rejected the action
 * - Edit: User wants to modify the action (future use)
 */
enum ActionDecision: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Edit = 'edit';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function isRejected(): bool
    {
        return $this === self::Rejected;
    }
}
