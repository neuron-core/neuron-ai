<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Suspension\Suspension;
use NeuronAI\Workflow\Resume\ResumeInput;

final class ActiveSuspension
{
    public function __construct(
        public readonly Suspension $suspension,
        public readonly string $stepId,
        public readonly ?string $branchId = null,
        public readonly ?ResumeInput $input = null,
    ) {
    }

    public function withInput(ResumeInput $input): self
    {
        return new self($this->suspension, $this->stepId, $this->branchId, $input);
    }
}
