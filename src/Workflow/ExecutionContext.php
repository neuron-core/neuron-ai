<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\Ignition;

use function serialize;
use function unserialize;

/** Authoritative identity and detached original input for one admitted segment. */
final class ExecutionContext
{
    protected readonly string $input;

    public function __construct(
        public readonly string $workflowId,
        public readonly string $runId,
        public readonly int $executionAttempt,
        Ignition $ignition,
    ) {
        $this->input = serialize($ignition->startEvent);
    }

    public function startEvent(): Event
    {
        return unserialize($this->input);
    }
}
