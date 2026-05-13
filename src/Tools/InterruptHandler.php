<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Trait for tools implementing HasInterrupt.
 *
 * Provides the standard property storage and getter/setter implementations
 * for interrupt and resume requests.
 *
 * @mixin HasInterrupt&ToolInterface
 */
trait InterruptHandler
{
    protected ?InterruptRequest $interruptRequest = null;
    protected ?InterruptRequest $resumeRequest = null;

    public function setInterruptRequest(?InterruptRequest $request): void
    {
        $this->interruptRequest = $request;
    }

    public function getInterruptRequest(): ?InterruptRequest
    {
        return $this->interruptRequest;
    }

    public function setResumeRequest(?InterruptRequest $request): void
    {
        $this->resumeRequest = $request;
    }

    public function getResumeRequest(): ?InterruptRequest
    {
        return $this->resumeRequest;
    }
}
