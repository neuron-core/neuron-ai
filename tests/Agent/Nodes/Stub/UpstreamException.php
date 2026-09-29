<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes\Stub;

use RuntimeException;

/**
 * An exception whose constructor is not (message, code), as many domain and
 * HTTP client exceptions are.
 */
class UpstreamException extends RuntimeException
{
    /** @param array{status: int} $details */
    public function __construct(public readonly array $details)
    {
        parent::__construct("Upstream failed with status {$details['status']}");
    }
}
