<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Resume\ResumeInput;

final class ActiveInterrupt
{
    public function __construct(
        public readonly InterruptRequest $request,
        public readonly ?ResumeInput $input = null,
    ) {
    }

    public function withInput(ResumeInput $input): self
    {
        return new self($this->request, $input);
    }
}
