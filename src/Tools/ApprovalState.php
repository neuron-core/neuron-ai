<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Per-tool-call approval state. A tool with no approval involvement
 * carries null instead of a case — absence means "not gated", not "pending".
 */
enum ApprovalState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

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
