<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

use NeuronAI\Workflow\RefusalReason;
use Throwable;

/**
 * The engine refused a request, or a write of a segment that lost its run,
 * because of the state the run is in. What it refused changed nothing; the
 * reason tells the caller what it can do next.
 */
class WorkflowRefusedException extends WorkflowException
{
    public function __construct(
        string $message,
        public readonly RefusalReason $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
