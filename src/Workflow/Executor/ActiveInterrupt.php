<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;

use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

final class ActiveInterrupt
{
    public function __construct(
        public readonly InterruptRequest $request,
        public readonly ?ResumeInput $input = null,
    ) {
    }

    public function withInput(ResumeInput $input): self
    {
        if ($this->input instanceof ResumeInput) {
            $flags = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
            if (
                $this->input->kind !== $input->kind
                || json_encode($this->input->payload, $flags) !== json_encode($input->payload, $flags)
            ) {
                throw new WorkflowException(
                    "Interrupt {$this->request->getId()} already has an accepted input; its answer cannot change."
                );
            }

            return $this;
        }

        return new self($this->request, $input);
    }
}
