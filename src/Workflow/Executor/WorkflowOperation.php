<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\WorkflowState;

/** @internal An operation receipt lives and is deleted with its run partition. */
final class WorkflowOperation
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly Ignition $ignition,
        public readonly ?WorkflowState $outcome = null,
        public readonly ?string $failure = null,
    ) {
    }

    public function settled(WorkflowState $state, ?string $failure = null): self
    {
        return new self($this->fingerprint, $this->ignition, $state, $failure);
    }
}
